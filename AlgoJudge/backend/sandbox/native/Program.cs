using System.Text.Json;

namespace AlgoJudge.NativeSandbox;

internal static class Program
{
    private static readonly JsonSerializerOptions Json = new() { PropertyNamingPolicy = JsonNamingPolicy.CamelCase, PropertyNameCaseInsensitive = true, UnmappedMemberHandling = System.Text.Json.Serialization.JsonUnmappedMemberHandling.Disallow };
    internal static string SafeError(Exception exception) => exception is SandboxException ? exception.Message :
        exception is UnauthorizedAccessException ? "access_denied" : exception is JsonException ? "invalid_json" :
        exception is IOException ? "filesystem_io_failed" : "helper_internal_error";
    private static void Print(object value) => Console.Out.WriteLine(JsonSerializer.Serialize(value, Json));
    private static int Main(string[] args)
    {
        try
        {
            if (!OperatingSystem.IsWindows()) throw new SandboxException("windows_required");
            if (args.Length == 1 && args[0] == "--profile-info")
            { Print(new { profile_name = Provisioning.ProfileBase, profile_sid = Provisioning.ProfileSid(Provisioning.ProfileBase, true).Value, isolation = "appcontainer_zero_capabilities_job_object" }); return 0; }
            if (args.Length == 2 && args[0] == "--init-root") { Print(Provisioning.Init(args[1])); return 0; }
            if (args.Length == 2 && args[0] == "--create-job") { Print(Provisioning.CreateJob(args[1])); return 0; }
            if (args.Length == 3 && args[0] == "--release-job") { Print(Provisioning.ReleaseJob(args[1], args[2])); return 0; }
            if (args.Length == 2 && args[0] == "--grant-runtime") { Print(Provisioning.GrantRuntime(args[1], null)); return 0; }
            if (args.Length == 3 && args[0] == "--seal-runtime") { Print(Provisioning.GrantRuntime(args[1], args[2])); return 0; }
            if (args.Length != 1 || args[0].StartsWith("--", StringComparison.Ordinal)) throw new SandboxException("invalid_arguments");
            string configPath = Provisioning.Full(args[0]);
            if (!File.Exists(configPath) || new FileInfo(configPath).Length > 128 * 1024) throw new SandboxException("invalid_config_file");
            RunConfig config = JsonSerializer.Deserialize<RunConfig>(File.ReadAllText(configPath), Json) ?? throw new SandboxException("invalid_config");
            string id = config.Validate(configPath);
            // The protected control directory is never readable/writable by the AppContainer.
            RunResult result;
            using (new FileStream(Path.Combine(Path.GetDirectoryName(config.ResultPath)!, ".run-lock"), FileMode.OpenOrCreate, FileAccess.ReadWrite, FileShare.None))
            {
                result = Runner.Run(config, id);
                string temp = config.ResultPath + "." + Guid.NewGuid().ToString("N") + ".tmp";
                File.WriteAllText(temp, JsonSerializer.Serialize(result, Json)); File.Move(temp, config.ResultPath, true);
            }
            Print(result); return result.InfrastructureError == null ? 0 : 2;
        }
        catch (Exception ex)
        { Print(new RunResult { InfrastructureError = SafeError(ex) }); return 2; }
    }
}
