<#
User rights for the spike's hidden account (D86). A scheduled task that runs as an account with a stored
password signs that account in as a batch job, and Windows refuses the sign-in (status 0xC000015B) unless the
account holds "Log on as a batch job" (SeBatchLogonRight). Task Scheduler does not grant it by itself, so the
spike does, through Windows' own LSA policy calls, and removes it again. haq-spike.ps1 dot-sources this file,
and so does CI's test of it on a Windows runner (test-account-rights.ps1).
#>

function Initialize-AccountRight {
    if ('HaqSpike.AccountRights' -as [type]) { return }
    Add-Type -TypeDefinition @'
using System;
using System.Collections.Generic;
using System.ComponentModel;
using System.Runtime.InteropServices;
using System.Security.Principal;

namespace HaqSpike
{
    public static class AccountRights
    {
        [StructLayout(LayoutKind.Sequential)]
        private struct LsaString
        {
            public ushort Length;
            public ushort MaximumLength;
            public IntPtr Buffer;
        }

        [StructLayout(LayoutKind.Sequential)]
        private struct LsaObjectAttributes
        {
            public int Length;
            public IntPtr RootDirectory;
            public IntPtr ObjectName;
            public uint Attributes;
            public IntPtr SecurityDescriptor;
            public IntPtr SecurityQualityOfService;
        }

        [DllImport("advapi32.dll")]
        private static extern uint LsaOpenPolicy(IntPtr systemName, ref LsaObjectAttributes attributes, uint access, out IntPtr policy);

        [DllImport("advapi32.dll")]
        private static extern uint LsaAddAccountRights(IntPtr policy, byte[] sid, LsaString[] rights, uint count);

        [DllImport("advapi32.dll")]
        private static extern uint LsaRemoveAccountRights(IntPtr policy, byte[] sid, [MarshalAs(UnmanagedType.U1)] bool all, LsaString[] rights, uint count);

        [DllImport("advapi32.dll")]
        private static extern uint LsaEnumerateAccountRights(IntPtr policy, byte[] sid, out IntPtr rights, out uint count);

        [DllImport("advapi32.dll")]
        private static extern uint LsaFreeMemory(IntPtr buffer);

        [DllImport("advapi32.dll")]
        private static extern uint LsaClose(IntPtr policy);

        [DllImport("advapi32.dll")]
        private static extern int LsaNtStatusToWinError(uint status);

        private const uint PolicyCreateAccount = 0x00000010;
        private const uint PolicyLookupNames = 0x00000800;
        private const uint StatusObjectNameNotFound = 0xC0000034;

        public static void Grant(string sid, string right)
        {
            Change(sid, right, true);
        }

        public static void Revoke(string sid, string right)
        {
            Change(sid, right, false);
        }

        // The rights the account holds itself, not through its groups.
        public static string[] Held(string sid)
        {
            IntPtr policy = Open();
            try
            {
                IntPtr buffer;
                uint count;
                uint status = LsaEnumerateAccountRights(policy, Binary(sid), out buffer, out count);
                if (status == StatusObjectNameNotFound)
                {
                    return new string[0];
                }
                Check(status);
                try
                {
                    List<string> held = new List<string>();
                    int size = Marshal.SizeOf(typeof(LsaString));
                    for (int i = 0; i < count; i++)
                    {
                        LsaString s = (LsaString)Marshal.PtrToStructure(new IntPtr(buffer.ToInt64() + (long)i * size), typeof(LsaString));
                        held.Add(Marshal.PtrToStringUni(s.Buffer, s.Length / 2));
                    }
                    return held.ToArray();
                }
                finally
                {
                    LsaFreeMemory(buffer);
                }
            }
            finally
            {
                LsaClose(policy);
            }
        }

        private static void Change(string sid, string right, bool grant)
        {
            IntPtr policy = Open();
            IntPtr text = Marshal.StringToHGlobalUni(right);
            try
            {
                LsaString[] names = new LsaString[1];
                names[0].Length = (ushort)(right.Length * 2);
                names[0].MaximumLength = (ushort)(right.Length * 2 + 2);
                names[0].Buffer = text;
                uint status = grant
                    ? LsaAddAccountRights(policy, Binary(sid), names, 1)
                    : LsaRemoveAccountRights(policy, Binary(sid), false, names, 1);
                // An account that holds no rights of its own has nothing to revoke.
                if (!grant && status == StatusObjectNameNotFound)
                {
                    return;
                }
                Check(status);
            }
            finally
            {
                Marshal.FreeHGlobal(text);
                LsaClose(policy);
            }
        }

        private static IntPtr Open()
        {
            LsaObjectAttributes attributes = new LsaObjectAttributes();
            attributes.Length = Marshal.SizeOf(typeof(LsaObjectAttributes));
            IntPtr policy;
            Check(LsaOpenPolicy(IntPtr.Zero, ref attributes, PolicyCreateAccount | PolicyLookupNames, out policy));
            return policy;
        }

        private static byte[] Binary(string sid)
        {
            SecurityIdentifier id = new SecurityIdentifier(sid);
            byte[] bytes = new byte[id.BinaryLength];
            id.GetBinaryForm(bytes, 0);
            return bytes;
        }

        private static void Check(uint status)
        {
            if (status != 0)
            {
                throw new Win32Exception(LsaNtStatusToWinError(status));
            }
        }
    }
}
'@
}

function Get-AccountSid([string] $Account) {
    return (New-Object Security.Principal.NTAccount($Account)).Translate([Security.Principal.SecurityIdentifier]).Value
}

# Gives the account one right, such as SeBatchLogonRight. Granting a right it already holds changes nothing.
function Grant-AccountRight([string] $Account, [string] $Right) {
    Initialize-AccountRight
    [HaqSpike.AccountRights]::Grant((Get-AccountSid $Account), $Right)
}

# Takes one right away from the account. Revoking a right it does not hold is not a fault.
function Revoke-AccountRight([string] $Account, [string] $Right) {
    Initialize-AccountRight
    [HaqSpike.AccountRights]::Revoke((Get-AccountSid $Account), $Right)
}

# The rights the account holds itself, not through its groups.
function Get-AccountRight([string] $Account) {
    Initialize-AccountRight
    return [HaqSpike.AccountRights]::Held((Get-AccountSid $Account))
}
