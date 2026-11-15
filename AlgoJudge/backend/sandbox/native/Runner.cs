using System.Diagnostics;
using System.Runtime.InteropServices;
using System.Security.Principal;
using System.Text;
using System.Text.Json.Serialization;
using Microsoft.Win32.SafeHandles;

namespace AlgoJudge.NativeSandbox;

internal sealed class RunConfig
{
    public string SandboxRoot { get; set; } = "";
    public string Executable { get; set; } = "";
    public string[] Args { get; set; } = [];
    public string WorkDir { get; set; } = "";
    public string StdinPath { get; set; } = "";
    public string StdoutPath { get; set; } = "";
    public string StderrPath { get; set; } = "";
    public string ResultPath { get; set; } = "";
    public int TimeLimitMs { get; set; }
    public long MemoryLimitKb { get; set; }
    public int MaxStdoutBytes { get; set; } = 65536;
    public int MaxStderrBytes { get; set; } = 32768;
    public string Phase { get; set; } = "";

    internal string Validate(string configPath)
    {
        SandboxRoot = Provisioning.Root(SandboxRoot); WorkDir = Provisioning.Full(WorkDir);
        string id = Provisioning.JobId(Path.GetFileName(WorkDir));
        if (!string.Equals(WorkDir, Path.Combine(SandboxRoot, "jobs", id), StringComparison.OrdinalIgnoreCase)) throw new SandboxException("invalid_work_dir");
        string control = Provisioning.ValidateJob(SandboxRoot, id);
        string ControlFile(string input)
        {
            string value = Provisioning.Full(input);
            if (!string.Equals(Path.GetDirectoryName(value), control, StringComparison.OrdinalIgnoreCase) || Path.GetFileName(value).StartsWith('.'))
                throw new SandboxException("io_outside_control");
            return value;
        }
        StdinPath = ControlFile(StdinPath); StdoutPath = ControlFile(StdoutPath); StderrPath = ControlFile(StderrPath); ResultPath = ControlFile(ResultPath);
        string[] paths = [StdinPath, StdoutPath, StderrPath, ResultPath, ControlFile(configPath)];
        if (paths.Distinct(StringComparer.OrdinalIgnoreCase).Count() != paths.Length) throw new SandboxException("io_paths_must_differ");
        if (!File.Exists(StdinPath) || new FileInfo(StdinPath).Length > 64 * 1024 * 1024) throw new SandboxException("invalid_stdin_file");
        Executable = Provisioning.Full(Executable);
        if (!File.Exists(Executable) || !Executable.EndsWith(".exe", StringComparison.OrdinalIgnoreCase) ||
            !(Provisioning.Under(Executable, Path.Combine(SandboxRoot, "runtimes")) || Provisioning.Under(Executable, WorkDir)))
            throw new SandboxException("executable_outside_sandbox");
        if (Args == null || Args.Length > 128 || Args.Any(s => s == null || s.Contains('\0')) || Executable.Contains('\0')) throw new SandboxException("invalid_arguments");
        if (TimeLimitMs < 1 || TimeLimitMs > 120000 || MemoryLimitKb < 16384 || MemoryLimitKb > 8L * 1024 * 1024 ||
            MaxStdoutBytes < 1 || MaxStdoutBytes > 65536 || MaxStderrBytes < 1 || MaxStderrBytes > 32768 || Phase is not ("compile" or "run"))
            throw new SandboxException("invalid_limits_or_phase");
        return id;
    }
}

internal sealed class RunResult
{
    [JsonPropertyName("exit_code")] public long? ExitCode { get; set; }
    [JsonPropertyName("timed_out")] public bool TimedOut { get; set; }
    [JsonPropertyName("output_limit")] public bool OutputLimit { get; set; }
    [JsonPropertyName("memory_limit")] public bool MemoryLimit { get; set; }
    [JsonPropertyName("time_ms")] public long TimeMs { get; set; }
    [JsonPropertyName("stdout_bytes")] public long StdoutBytes { get; set; }
    [JsonPropertyName("stderr_bytes")] public long StderrBytes { get; set; }
    [JsonPropertyName("infrastructure_error")] public string? InfrastructureError { get; set; }
}

internal sealed class DrainState { internal long Bytes; internal int Exceeded; }

internal static class Runner
{
    internal static string Quote(string value)
    {
        StringBuilder b = new("\""); int slashes = 0;
        foreach (char c in value)
        {
            if (c == '\\') { slashes++; continue; }
            if (c == '"') { b.Append('\\', slashes * 2 + 1).Append('"'); slashes = 0; continue; }
            b.Append('\\', slashes).Append(c); slashes = 0;
        }
        return b.Append('\\', slashes * 2).Append('"').ToString();
    }
    private static (SafeFileHandle Read, SafeFileHandle Write) Pipe(bool childReads)
    {
        Native.SECURITY_ATTRIBUTES sa = new() { nLength = (uint)Marshal.SizeOf<Native.SECURITY_ATTRIBUTES>(), bInheritHandle = true };
        Native.Check(Native.CreatePipe(out SafeFileHandle read, out SafeFileHandle write, ref sa, 4096), "pipe_create");
        try { Native.Check(Native.SetHandleInformation(childReads ? write : read, Native.HANDLE_FLAG_INHERIT, 0), "pipe_restrict"); return (read, write); }
        catch { read.Dispose(); write.Dispose(); throw; }
    }
    private static Task Drain(SafeFileHandle handle, string path, int limit, DrainState state) => Task.Run(() =>
    {
        using FileStream input = new(handle, FileAccess.Read, 4096, false);
        using FileStream output = new(path, FileMode.Create, FileAccess.Write, FileShare.Read);
        byte[] buffer = new byte[4096]; int n; long saved = 0;
        while ((n = input.Read(buffer)) != 0)
        {
            long total = Interlocked.Add(ref state.Bytes, n);
            int keep = (int)Math.Min(n, Math.Max(0, limit - saved));
            if (keep > 0) { output.Write(buffer, 0, keep); saved += keep; }
            if (total > limit) Interlocked.Exchange(ref state.Exceeded, 1);
        }
    });
    private static Task Feed(SafeFileHandle handle, string path) => Task.Run(() =>
    {
        using FileStream output = new(handle, FileAccess.Write, 4096, false);
        using FileStream input = new(path, FileMode.Open, FileAccess.Read, FileShare.Read);
        try { input.CopyTo(output); } catch (IOException) { /* Child closes stdin early or the job is terminated. */ }
    });
    private static IntPtr EnvironmentBlock(RunConfig c)
    {
        string windows = Environment.GetFolderPath(Environment.SpecialFolder.Windows);
        string runtime = Path.Combine(c.SandboxRoot, "runtimes");
        SortedDictionary<string, string> env = new(StringComparer.OrdinalIgnoreCase)
        {
            ["SystemRoot"] = windows, ["WINDIR"] = windows, ["SystemDrive"] = Path.GetPathRoot(windows)!.TrimEnd('\\'),
            ["TEMP"] = c.WorkDir, ["TMP"] = c.WorkDir, ["HOME"] = c.WorkDir, ["USERPROFILE"] = c.WorkDir,
            ["LOCALAPPDATA"] = c.WorkDir, ["APPDATA"] = c.WorkDir, ["PYTHONIOENCODING"] = "utf-8", ["PYTHONUTF8"] = "1",
            ["PYTHONDONTWRITEBYTECODE"] = "1", ["PYTHONNOUSERSITE"] = "1", ["NO_COLOR"] = "1",
            ["PATH"] = string.Join(';', new[] { Path.GetDirectoryName(c.Executable)!, Path.Combine(runtime, "mingw", "bin"),
                Path.Combine(runtime, "java", "bin"), Path.Combine(runtime, "python"), Path.Combine(runtime, "php"), Path.Combine(runtime, "node"), Path.Combine(windows, "System32") })
        };
        return Marshal.StringToHGlobalUni(string.Join('\0', env.Select(e => e.Key + "=" + e.Value)) + "\0\0");
    }
    private static void VerifyToken(IntPtr process, SecurityIdentifier expected)
    {
        Native.Check(Native.OpenProcessToken(process, 8, out SafeFileHandle token), "token_open");
        using (token)
        {
            IntPtr buffer = Marshal.AllocHGlobal(4096);
            try
            {
                Native.Check(Native.GetTokenInformation(token, 29, buffer, 4096, out _), "token_appcontainer");
                if (Marshal.ReadInt32(buffer) != 1) throw new SandboxException("not_appcontainer");
                Native.Check(Native.GetTokenInformation(token, 30, buffer, 4096, out _), "token_capabilities");
                if (Marshal.ReadInt32(buffer) != 0) throw new SandboxException("unexpected_capabilities");
                Native.Check(Native.GetTokenInformation(token, 31, buffer, 4096, out _), "token_sid");
                if (!expected.Equals(new SecurityIdentifier(Marshal.ReadIntPtr(buffer)))) throw new SandboxException("unexpected_appcontainer_sid");
            }
            finally { Marshal.FreeHGlobal(buffer); }
        }
    }
    internal static RunResult Run(RunConfig c, string id)
    {
        RunResult result = new(); Stopwatch clock = Stopwatch.StartNew();
        IntPtr job = IntPtr.Zero, port = IntPtr.Zero, attributes = IntPtr.Zero, caps = IntPtr.Zero, sid = IntPtr.Zero, handleList = IntPtr.Zero, environment = IntPtr.Zero;
        Native.PROCESS_INFORMATION process = new();
        bool assigned = false, attributesInitialized = false;
        Task? outputTask = null, errorTask = null, inputTask = null;
        DrainState stdout = new(), stderr = new();
        var input = Pipe(true); var output = Pipe(false); var error = Pipe(false);
        try
        {
            job = Native.CreateJobObjectW(IntPtr.Zero, IntPtr.Zero); Native.Check(job != IntPtr.Zero, "job_create");
            Native.JOBOBJECT_EXTENDED_LIMIT_INFORMATION limits = new()
            {
                BasicLimitInformation = new() { LimitFlags = Native.JOB_OBJECT_LIMIT_KILL_ON_JOB_CLOSE | Native.JOB_OBJECT_LIMIT_PROCESS_MEMORY |
                    Native.JOB_OBJECT_LIMIT_JOB_MEMORY | Native.JOB_OBJECT_LIMIT_ACTIVE_PROCESS | Native.JOB_OBJECT_LIMIT_DIE_ON_UNHANDLED_EXCEPTION,
                    ActiveProcessLimit = 16 },
                ProcessMemoryLimit = checked((nuint)c.MemoryLimitKb * 1024), JobMemoryLimit = checked((nuint)c.MemoryLimitKb * 1024)
            };
            Native.SetJob(job, 9, limits);
            // UI restrictions block clipboard, desktops, window handles, display settings and global atoms.
            Native.SetJob(job, 4, 0xffU);
            try { Native.SetJob(job, 15, new Native.JOBOBJECT_CPU_RATE_CONTROL_INFORMATION { ControlFlags = 5, CpuRate = 5000 }); }
            catch (SandboxException ex) when (ex.Message is "job_limit_win32_50" or "job_limit_win32_87") { /* Older hosts: wall/job memory limits remain mandatory. */ }
            port = Native.CreateIoCompletionPort(new IntPtr(-1), IntPtr.Zero, 0, 1); Native.Check(port != IntPtr.Zero, "completion_port");
            Native.SetJob(job, 7, new Native.JOBOBJECT_ASSOCIATE_COMPLETION_PORT { CompletionKey = new IntPtr(1), CompletionPort = port });
            SecurityIdentifier profileSid = Provisioning.ProfileSid(Provisioning.Profile(id), false);
            byte[] sidBytes = new byte[profileSid.BinaryLength]; profileSid.GetBinaryForm(sidBytes, 0);
            sid = Marshal.AllocHGlobal(sidBytes.Length); Marshal.Copy(sidBytes, 0, sid, sidBytes.Length);
            caps = Marshal.AllocHGlobal(Marshal.SizeOf<Native.SECURITY_CAPABILITIES>());
            Marshal.StructureToPtr(new Native.SECURITY_CAPABILITIES { AppContainerSid = sid }, caps, false);
            nuint size = 0; Native.InitializeProcThreadAttributeList(IntPtr.Zero, 2, 0, ref size);
            attributes = Marshal.AllocHGlobal(checked((int)size));
            Native.Check(Native.InitializeProcThreadAttributeList(attributes, 2, 0, ref size), "attribute_initialize"); attributesInitialized = true;
            Native.Check(Native.UpdateProcThreadAttribute(attributes, 0, Native.PROC_THREAD_ATTRIBUTE_SECURITY_CAPABILITIES, caps,
                (nuint)Marshal.SizeOf<Native.SECURITY_CAPABILITIES>(), IntPtr.Zero, IntPtr.Zero), "attribute_appcontainer");
            handleList = Marshal.AllocHGlobal(IntPtr.Size * 3);
            Marshal.WriteIntPtr(handleList, 0, input.Read.DangerousGetHandle()); Marshal.WriteIntPtr(handleList, IntPtr.Size, output.Write.DangerousGetHandle());
            Marshal.WriteIntPtr(handleList, IntPtr.Size * 2, error.Write.DangerousGetHandle());
            Native.Check(Native.UpdateProcThreadAttribute(attributes, 0, Native.PROC_THREAD_ATTRIBUTE_HANDLE_LIST, handleList,
                (nuint)(IntPtr.Size * 3), IntPtr.Zero, IntPtr.Zero), "attribute_handles");
            Native.STARTUPINFOEX startup = new()
            {
                StartupInfo = new() { cb = (uint)Marshal.SizeOf<Native.STARTUPINFOEX>(), dwFlags = Native.STARTF_USESTDHANDLES,
                    hStdInput = input.Read.DangerousGetHandle(), hStdOutput = output.Write.DangerousGetHandle(), hStdError = error.Write.DangerousGetHandle() },
                lpAttributeList = attributes
            };
            string command = string.Join(' ', new[] { Quote(c.Executable) }.Concat(c.Args.Select(Quote)));
            if (command.Length > 30000) throw new SandboxException("command_too_long");
            environment = EnvironmentBlock(c);
            Native.Check(Native.CreateProcessW(c.Executable, new StringBuilder(command), IntPtr.Zero, IntPtr.Zero, true,
                Native.CREATE_SUSPENDED | Native.EXTENDED_STARTUPINFO_PRESENT | Native.CREATE_UNICODE_ENVIRONMENT | Native.CREATE_NO_WINDOW,
                environment, c.WorkDir, ref startup, out process), "process_create");
            Native.Check(Native.AssignProcessToJobObject(job, process.hProcess), "job_assign"); assigned = true;
            VerifyToken(process.hProcess, profileSid);
            input.Read.Dispose(); output.Write.Dispose(); error.Write.Dispose();
            outputTask = Drain(output.Read, c.StdoutPath, c.MaxStdoutBytes, stdout);
            errorTask = Drain(error.Read, c.StderrPath, c.MaxStderrBytes, stderr);
            inputTask = Feed(input.Write, c.StdinPath);
            Native.Check(Native.ResumeThread(process.hThread) != uint.MaxValue, "process_resume");
            while (true)
            {
                while (Native.GetQueuedCompletionStatus(port, out uint message, out _, out _, 0))
                    if (message is Native.JOB_OBJECT_MSG_PROCESS_MEMORY_LIMIT or Native.JOB_OBJECT_MSG_JOB_MEMORY_LIMIT) result.MemoryLimit = true;
                result.OutputLimit |= Volatile.Read(ref stdout.Exceeded) != 0 || Volatile.Read(ref stderr.Exceeded) != 0;
                result.TimedOut = clock.ElapsedMilliseconds >= c.TimeLimitMs;
                if (outputTask.IsFaulted || errorTask.IsFaulted || inputTask.IsFaulted) throw new SandboxException("stdio_io_failed");
                if (result.MemoryLimit || result.OutputLimit || result.TimedOut) { Native.Check(Native.TerminateJobObject(job, 137), "job_terminate"); break; }
                var accounting = Native.QueryJob<Native.JOBOBJECT_BASIC_ACCOUNTING_INFORMATION>(job, 1);
                if (accounting.ActiveProcesses == 0) break;
                // Wait on the original process while alive, otherwise wait on a job notification for grandchildren.
                if (Native.WaitForSingleObject(process.hProcess, 10) == Native.WAIT_OBJECT_0)
                {
                    if (Native.GetQueuedCompletionStatus(port, out uint message, out _, out _, 10) &&
                        message is Native.JOB_OBJECT_MSG_PROCESS_MEMORY_LIMIT or Native.JOB_OBJECT_MSG_JOB_MEMORY_LIMIT) result.MemoryLimit = true;
                }
            }
            Native.TerminateJobObject(job, 137); // No background descendants survive normal root exit either.
            Native.WaitForSingleObject(process.hProcess, 2000);
            if (Native.GetExitCodeProcess(process.hProcess, out uint exit) && exit != Native.STILL_ACTIVE) result.ExitCode = unchecked((int)exit);
            if (result.ExitCode is -1073741801 or -1073741526) result.MemoryLimit = true; // STATUS_NO_MEMORY / STATUS_COMMITMENT_LIMIT
        }
        catch (Exception ex) { result.InfrastructureError = Program.SafeError(ex); }
        finally
        {
            if (job != IntPtr.Zero && assigned) Native.TerminateJobObject(job, 137);
            else if (process.hProcess != IntPtr.Zero) Native.TerminateProcess(process.hProcess, 137); // Still suspended; never executes outside job.
            input.Read.Dispose(); output.Write.Dispose(); error.Write.Dispose();
            // Closing the kill-on-close job kills every descendant before pipe drains are joined.
            if (job != IntPtr.Zero) Native.CloseHandle(job);
            Task[] tasks = new[] { outputTask, errorTask, inputTask }.OfType<Task>().ToArray();
            try { if (!Task.WaitAll(tasks, 5000)) result.InfrastructureError ??= "stdio_drain_timeout"; }
            catch (AggregateException) { result.InfrastructureError ??= "stdio_io_failed"; }
            input.Write.Dispose(); output.Read.Dispose(); error.Read.Dispose();
            result.StdoutBytes = Interlocked.Read(ref stdout.Bytes); result.StderrBytes = Interlocked.Read(ref stderr.Bytes);
            result.OutputLimit |= Volatile.Read(ref stdout.Exceeded) != 0 || Volatile.Read(ref stderr.Exceeded) != 0;
            result.TimeMs = clock.ElapsedMilliseconds;
            if (process.hThread != IntPtr.Zero) Native.CloseHandle(process.hThread);
            if (process.hProcess != IntPtr.Zero) Native.CloseHandle(process.hProcess);
            if (port != IntPtr.Zero) Native.CloseHandle(port);
            if (attributesInitialized) Native.DeleteProcThreadAttributeList(attributes);
            foreach (IntPtr block in new[] { attributes, caps, sid, handleList, environment }) if (block != IntPtr.Zero) Marshal.FreeHGlobal(block);
        }
        return result;
    }
}
