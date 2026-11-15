using System.Runtime.InteropServices;
using System.Text;
using Microsoft.Win32.SafeHandles;

namespace AlgoJudge.NativeSandbox;

internal static class Native
{
    internal const uint CREATE_SUSPENDED = 0x00000004, EXTENDED_STARTUPINFO_PRESENT = 0x00080000,
        CREATE_UNICODE_ENVIRONMENT = 0x00000400, CREATE_NO_WINDOW = 0x08000000;
    internal const uint STARTF_USESTDHANDLES = 0x100, HANDLE_FLAG_INHERIT = 1;
    internal const nuint PROC_THREAD_ATTRIBUTE_HANDLE_LIST = 0x00020002,
        PROC_THREAD_ATTRIBUTE_SECURITY_CAPABILITIES = 0x00020009;
    internal const uint JOB_OBJECT_LIMIT_PROCESS_MEMORY = 0x100, JOB_OBJECT_LIMIT_JOB_MEMORY = 0x200,
        JOB_OBJECT_LIMIT_ACTIVE_PROCESS = 0x8, JOB_OBJECT_LIMIT_KILL_ON_JOB_CLOSE = 0x2000,
        JOB_OBJECT_LIMIT_DIE_ON_UNHANDLED_EXCEPTION = 0x400;
    internal const uint WAIT_OBJECT_0 = 0, WAIT_TIMEOUT = 258, STILL_ACTIVE = 259;
    internal const uint JOB_OBJECT_MSG_PROCESS_MEMORY_LIMIT = 9, JOB_OBJECT_MSG_JOB_MEMORY_LIMIT = 10;

    [StructLayout(LayoutKind.Sequential)] internal struct SECURITY_ATTRIBUTES
    { internal uint nLength; internal IntPtr lpSecurityDescriptor; [MarshalAs(UnmanagedType.Bool)] internal bool bInheritHandle; }
    [StructLayout(LayoutKind.Sequential)] internal struct SECURITY_CAPABILITIES
    { internal IntPtr AppContainerSid, Capabilities; internal uint CapabilityCount, Reserved; }
    [StructLayout(LayoutKind.Sequential, CharSet = CharSet.Unicode)] internal struct STARTUPINFO
    {
        internal uint cb; internal IntPtr lpReserved, lpDesktop, lpTitle;
        internal uint dwX, dwY, dwXSize, dwYSize, dwXCountChars, dwYCountChars, dwFillAttribute, dwFlags;
        internal ushort wShowWindow, cbReserved2; internal IntPtr lpReserved2, hStdInput, hStdOutput, hStdError;
    }
    [StructLayout(LayoutKind.Sequential)] internal struct STARTUPINFOEX
    { internal STARTUPINFO StartupInfo; internal IntPtr lpAttributeList; }
    [StructLayout(LayoutKind.Sequential)] internal struct PROCESS_INFORMATION
    { internal IntPtr hProcess, hThread; internal uint dwProcessId, dwThreadId; }
    [StructLayout(LayoutKind.Sequential)] internal struct JOBOBJECT_BASIC_LIMIT_INFORMATION
    {
        internal long PerProcessUserTimeLimit, PerJobUserTimeLimit;
        internal uint LimitFlags; internal nuint MinimumWorkingSetSize, MaximumWorkingSetSize;
        internal uint ActiveProcessLimit; internal nuint Affinity; internal uint PriorityClass, SchedulingClass;
    }
    [StructLayout(LayoutKind.Sequential)] internal struct IO_COUNTERS
    { internal ulong ReadOperationCount, WriteOperationCount, OtherOperationCount, ReadTransferCount, WriteTransferCount, OtherTransferCount; }
    [StructLayout(LayoutKind.Sequential)] internal struct JOBOBJECT_EXTENDED_LIMIT_INFORMATION
    {
        internal JOBOBJECT_BASIC_LIMIT_INFORMATION BasicLimitInformation;
        internal IO_COUNTERS IoInfo; internal nuint ProcessMemoryLimit, JobMemoryLimit, PeakProcessMemoryUsed, PeakJobMemoryUsed;
    }
    [StructLayout(LayoutKind.Sequential)] internal struct JOBOBJECT_BASIC_ACCOUNTING_INFORMATION
    {
        internal long TotalUserTime, TotalKernelTime, ThisPeriodTotalUserTime, ThisPeriodTotalKernelTime;
        internal uint TotalPageFaultCount, TotalProcesses, ActiveProcesses, TotalTerminatedProcesses;
    }
    [StructLayout(LayoutKind.Sequential)] internal struct JOBOBJECT_ASSOCIATE_COMPLETION_PORT
    { internal IntPtr CompletionKey, CompletionPort; }
    [StructLayout(LayoutKind.Sequential)] internal struct JOBOBJECT_CPU_RATE_CONTROL_INFORMATION
    { internal uint ControlFlags, CpuRate; }
    [StructLayout(LayoutKind.Sequential)] internal struct TOKEN_APPCONTAINER_INFORMATION
    { internal IntPtr TokenAppContainer; }

    [DllImport("userenv.dll", CharSet = CharSet.Unicode)] internal static extern int CreateAppContainerProfile(
        string name, string displayName, string description, IntPtr capabilities, uint count, out IntPtr sid);
    [DllImport("userenv.dll", CharSet = CharSet.Unicode)] internal static extern int DeriveAppContainerSidFromAppContainerName(string name, out IntPtr sid);
    [DllImport("userenv.dll", CharSet = CharSet.Unicode)] internal static extern int DeleteAppContainerProfile(string name);
    [DllImport("advapi32.dll")] internal static extern IntPtr FreeSid(IntPtr sid);
    [DllImport("advapi32.dll", SetLastError = true)] [return: MarshalAs(UnmanagedType.Bool)]
    internal static extern bool OpenProcessToken(IntPtr process, uint access, out SafeFileHandle token);
    [DllImport("advapi32.dll", SetLastError = true)] [return: MarshalAs(UnmanagedType.Bool)]
    internal static extern bool GetTokenInformation(SafeFileHandle token, int infoClass, IntPtr info, uint length, out uint returnLength);
    [DllImport("kernel32.dll", SetLastError = true)] [return: MarshalAs(UnmanagedType.Bool)]
    internal static extern bool CreatePipe(out SafeFileHandle read, out SafeFileHandle write, ref SECURITY_ATTRIBUTES attributes, uint size);
    [DllImport("kernel32.dll", SetLastError = true)] [return: MarshalAs(UnmanagedType.Bool)]
    internal static extern bool SetHandleInformation(SafeFileHandle handle, uint mask, uint flags);
    [DllImport("kernel32.dll", SetLastError = true)] internal static extern IntPtr CreateJobObjectW(IntPtr attributes, IntPtr name);
    [DllImport("kernel32.dll", SetLastError = true)] [return: MarshalAs(UnmanagedType.Bool)]
    internal static extern bool SetInformationJobObject(IntPtr job, int informationClass, IntPtr info, uint length);
    [DllImport("kernel32.dll", SetLastError = true)] [return: MarshalAs(UnmanagedType.Bool)]
    internal static extern bool QueryInformationJobObject(IntPtr job, int informationClass, IntPtr info, uint length, IntPtr returnLength);
    [DllImport("kernel32.dll", SetLastError = true)] [return: MarshalAs(UnmanagedType.Bool)]
    internal static extern bool AssignProcessToJobObject(IntPtr job, IntPtr process);
    [DllImport("kernel32.dll", SetLastError = true)] [return: MarshalAs(UnmanagedType.Bool)]
    internal static extern bool TerminateJobObject(IntPtr job, uint code);
    [DllImport("kernel32.dll", SetLastError = true)] [return: MarshalAs(UnmanagedType.Bool)]
    internal static extern bool TerminateProcess(IntPtr process, uint code);
    [DllImport("kernel32.dll", SetLastError = true)] internal static extern IntPtr CreateIoCompletionPort(IntPtr file, IntPtr existing, nuint key, uint threads);
    [DllImport("kernel32.dll", SetLastError = true)] [return: MarshalAs(UnmanagedType.Bool)]
    internal static extern bool GetQueuedCompletionStatus(IntPtr port, out uint bytes, out nuint key, out IntPtr overlapped, uint milliseconds);
    [DllImport("kernel32.dll", SetLastError = true)] [return: MarshalAs(UnmanagedType.Bool)]
    internal static extern bool InitializeProcThreadAttributeList(IntPtr list, int count, uint flags, ref nuint size);
    [DllImport("kernel32.dll", SetLastError = true)] [return: MarshalAs(UnmanagedType.Bool)]
    internal static extern bool UpdateProcThreadAttribute(IntPtr list, uint flags, nuint attribute, IntPtr value, nuint size, IntPtr previous, IntPtr returnSize);
    [DllImport("kernel32.dll")] internal static extern void DeleteProcThreadAttributeList(IntPtr list);
    [DllImport("kernel32.dll", CharSet = CharSet.Unicode, SetLastError = true)] [return: MarshalAs(UnmanagedType.Bool)]
    internal static extern bool CreateProcessW(string application, StringBuilder commandLine, IntPtr processAttributes,
        IntPtr threadAttributes, [MarshalAs(UnmanagedType.Bool)] bool inheritHandles, uint flags, IntPtr environment,
        string currentDirectory, ref STARTUPINFOEX startup, out PROCESS_INFORMATION process);
    [DllImport("kernel32.dll", SetLastError = true)] internal static extern uint ResumeThread(IntPtr thread);
    [DllImport("kernel32.dll", SetLastError = true)] internal static extern uint WaitForSingleObject(IntPtr handle, uint milliseconds);
    [DllImport("kernel32.dll", SetLastError = true)] [return: MarshalAs(UnmanagedType.Bool)]
    internal static extern bool GetExitCodeProcess(IntPtr process, out uint code);
    [DllImport("kernel32.dll", SetLastError = true)] [return: MarshalAs(UnmanagedType.Bool)]
    internal static extern bool CloseHandle(IntPtr handle);

    internal static void Check(bool success, string operation)
    { if (!success) throw new SandboxException(operation + "_win32_" + Marshal.GetLastWin32Error()); }
    internal static void SetJob<T>(IntPtr job, int infoClass, T value) where T : struct
    {
        IntPtr data = Marshal.AllocHGlobal(Marshal.SizeOf<T>());
        try { Marshal.StructureToPtr(value, data, false); Check(SetInformationJobObject(job, infoClass, data, (uint)Marshal.SizeOf<T>()), "job_limit"); }
        finally { Marshal.FreeHGlobal(data); }
    }
    internal static T QueryJob<T>(IntPtr job, int infoClass) where T : struct
    {
        IntPtr data = Marshal.AllocHGlobal(Marshal.SizeOf<T>());
        try { Check(QueryInformationJobObject(job, infoClass, data, (uint)Marshal.SizeOf<T>(), IntPtr.Zero), "job_query"); return Marshal.PtrToStructure<T>(data); }
        finally { Marshal.FreeHGlobal(data); }
    }
}

internal sealed class SandboxException(string code) : Exception(code);
