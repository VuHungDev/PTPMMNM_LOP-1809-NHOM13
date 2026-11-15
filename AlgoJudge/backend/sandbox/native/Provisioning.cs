using System.Security.AccessControl;
using System.Security.Principal;
using System.Runtime.InteropServices;
using System.Text.Json;

namespace AlgoJudge.NativeSandbox;

internal static class Provisioning
{
    internal const string ProfileBase = "AlgoJudge.NativeSandbox";
    private const string Marker = ".algojudge-native-root.json";
    internal static readonly SecurityIdentifier User = WindowsIdentity.GetCurrent().User ?? throw new SandboxException("identity_unavailable");
    private static readonly SecurityIdentifier SystemSid = new("S-1-5-18"), AdminSid = new("S-1-5-32-544"), Packages = new("S-1-15-2-1");

    internal static string Full(string path)
    {
        if (string.IsNullOrWhiteSpace(path) || !Path.IsPathFullyQualified(path) || path.StartsWith("\\\\", StringComparison.Ordinal) || path.Contains(':', 2))
            throw new SandboxException("invalid_absolute_local_path");
        string full = Path.GetFullPath(path).TrimEnd(Path.DirectorySeparatorChar);
        if (full.Length < 4) throw new SandboxException("root_path_forbidden");
        NoReparse(full);
        return full;
    }
    internal static void NoReparse(string path)
    {
        string? part = path;
        while (part != null)
        {
            if ((File.Exists(part) || Directory.Exists(part)) && (File.GetAttributes(part) & FileAttributes.ReparsePoint) != 0)
                throw new SandboxException("reparse_path_forbidden");
            part = Path.GetDirectoryName(part);
        }
    }
    internal static bool Under(string path, string parent) => path.StartsWith(parent + Path.DirectorySeparatorChar, StringComparison.OrdinalIgnoreCase);
    private static void RootLocation(string root)
    {
        string lower = root.ToLowerInvariant();
        if (lower.Split(Path.DirectorySeparatorChar).Any(p => p is "htdocs" or "wwwroot" or "inetpub" or "xampp") ||
            Under(root, Environment.GetFolderPath(Environment.SpecialFolder.Windows)) ||
            Under(root, Environment.GetFolderPath(Environment.SpecialFolder.ProgramFiles)))
            throw new SandboxException("sandbox_root_location_forbidden");
    }
    private static DirectorySecurity Acl(SecurityIdentifier? app = null, FileSystemRights rights = FileSystemRights.ReadAndExecute)
    {
        DirectorySecurity acl = new();
        acl.SetAccessRuleProtection(true, false); acl.SetOwner(User);
        foreach (SecurityIdentifier sid in new[] { User, SystemSid, AdminSid })
            acl.AddAccessRule(new FileSystemAccessRule(sid, FileSystemRights.FullControl, InheritanceFlags.ContainerInherit | InheritanceFlags.ObjectInherit, PropagationFlags.None, AccessControlType.Allow));
        if (app != null) acl.AddAccessRule(new FileSystemAccessRule(app, rights, InheritanceFlags.ContainerInherit | InheritanceFlags.ObjectInherit, PropagationFlags.None, AccessControlType.Allow));
        return acl;
    }
    private static void CreateDirectory(string path, SecurityIdentifier? app = null, FileSystemRights rights = FileSystemRights.ReadAndExecute)
    { if (Directory.Exists(path) || File.Exists(path)) throw new SandboxException("destination_already_exists"); new DirectoryInfo(path).Create(Acl(app, rights)); }
    internal static string Root(string input)
    {
        string root = Full(input); RootLocation(root);
        string marker = Path.Combine(root, Marker); NoReparse(marker);
        if (!File.Exists(marker) || File.ReadAllText(marker) != "AlgoJudge.NativeSandbox.v1\n" + User.Value)
            throw new SandboxException("unowned_sandbox_root");
        DirectorySecurity security = new DirectoryInfo(root).GetAccessControl();
        if (!User.Equals(security.GetOwner(typeof(SecurityIdentifier))) || !security.AreAccessRulesProtected)
            throw new SandboxException("unsafe_sandbox_root_acl");
        foreach (FileSystemAccessRule rule in security.GetAccessRules(true, true, typeof(SecurityIdentifier)))
        {
            if (rule.AccessControlType != AccessControlType.Allow) continue;
            SecurityIdentifier sid = (SecurityIdentifier)rule.IdentityReference;
            if (sid.Equals(User) || sid.Equals(SystemSid) || sid.Equals(AdminSid)) continue;
            if (!sid.Equals(Packages) || (rule.FileSystemRights & ~(FileSystemRights.ReadAndExecute | FileSystemRights.Synchronize)) != 0)
                throw new SandboxException("unsafe_sandbox_root_acl");
        }
        return root;
    }
    internal static object Init(string input)
    {
        string root = Full(input); RootLocation(root);
        if (!Directory.Exists(Path.GetDirectoryName(root))) throw new SandboxException("root_parent_missing");
        CreateDirectory(root, Packages);
        foreach (string sub in new[] { "runtimes", "jobs", "control", "bin" })
            CreateDirectory(Path.Combine(root, sub), sub is "runtimes" or "jobs" ? Packages : null);
        File.WriteAllText(Path.Combine(root, Marker), "AlgoJudge.NativeSandbox.v1\n" + User.Value);
        // Root inheritance allows read traversal; the marker contains no secrets.
        return new { sandbox_root = root, profile_name = ProfileBase };
    }
    internal static string JobId(string id)
    { if (id.Length != 32 || id.Any(c => !char.IsAsciiHexDigit(c)) || id != id.ToLowerInvariant()) throw new SandboxException("invalid_job_id"); return id; }
    internal static string Profile(string id) => ProfileBase + "." + JobId(id);
    internal static SecurityIdentifier ProfileSid(string name, bool create)
    {
        IntPtr sid = IntPtr.Zero;
        try
        {
            int hr = create ? Native.CreateAppContainerProfile(name, name, "Isolated local judge; no capabilities", IntPtr.Zero, 0, out sid)
                : Native.DeriveAppContainerSidFromAppContainerName(name, out sid);
            if (create && hr == unchecked((int)0x800700B7)) hr = Native.DeriveAppContainerSidFromAppContainerName(name, out sid);
            if (hr < 0 || sid == IntPtr.Zero) throw new SandboxException("appcontainer_profile_hresult_" + unchecked((uint)hr).ToString("x8"));
            return new SecurityIdentifier(sid);
        }
        finally { if (sid != IntPtr.Zero) Native.FreeSid(sid); }
    }
    internal static object CreateJob(string input)
    {
        string root = Root(input), id = Guid.NewGuid().ToString("N"), profile = Profile(id);
        SecurityIdentifier sid = ProfileSid(profile, true);
        string work = Path.Combine(root, "jobs", id), control = Path.Combine(root, "control", id);
        CreateDirectory(control);
        File.WriteAllText(Path.Combine(control, ".job-owner"), User.Value + "\n" + id);
        CreateDirectory(work, sid, FileSystemRights.Modify);
        return new { job_id = id, work_dir = work, control_dir = control, profile_name = profile, profile_sid = sid.Value };
    }
    internal static string ValidateJob(string root, string id)
    {
        JobId(id); string control = Full(Path.Combine(root, "control", id));
        string marker = Full(Path.Combine(control, ".job-owner"));
        if (!File.Exists(marker) || File.ReadAllText(marker) != User.Value + "\n" + id) throw new SandboxException("unowned_job");
        string work = Full(Path.Combine(root, "jobs", id));
        if (!Directory.Exists(work) || !Directory.Exists(control)) throw new SandboxException("job_missing");
        return control;
    }
    internal static object GrantRuntime(string input, string? directory)
    {
        string root = Root(input), runtimeRoot = Full(Path.Combine(root, "runtimes"));
        string target = directory == null ? runtimeRoot : Full(directory);
        if (target != runtimeRoot && !Under(target, runtimeRoot)) throw new SandboxException("runtime_outside_owned_root");
        SealTree(target);
        return new { granted = true };
    }
    private static void SealTree(string path)
    {
        NoReparse(path);
        new DirectoryInfo(path).SetAccessControl(Acl(Packages));
        foreach (string item in Directory.EnumerateFileSystemEntries(path))
        {
            NoReparse(item);
            if (Directory.Exists(item)) SealTree(item);
            else
            {
                FileSecurity acl = new(); acl.SetAccessRuleProtection(true, false); acl.SetOwner(User);
                foreach (SecurityIdentifier sid in new[] { User, SystemSid, AdminSid }) acl.AddAccessRule(new FileSystemAccessRule(sid, FileSystemRights.FullControl, AccessControlType.Allow));
                acl.AddAccessRule(new FileSystemAccessRule(Packages, FileSystemRights.ReadAndExecute, AccessControlType.Allow));
                new FileInfo(item).SetAccessControl(acl);
            }
        }
    }
    private static void CheckTree(string directory)
    { NoReparse(directory); foreach (string entry in Directory.EnumerateFileSystemEntries(directory)) { NoReparse(entry); if (Directory.Exists(entry)) CheckTree(entry); } }
    internal static object ReleaseJob(string input, string id)
    {
        string root = Root(input), control = ValidateJob(root, id), work = Path.Combine(root, "jobs", id);
        // Never follow contestant-created junctions or delete directories without our protected marker.
        CheckTree(work); CheckTree(control);
        int hr = Native.DeleteAppContainerProfile(Profile(id));
        if (hr < 0 && hr != unchecked((int)0x80070002)) throw new SandboxException("profile_release_failed");
        Directory.Delete(work, true); Directory.Delete(control, true);
        return new { released = true };
    }
}
