# The Windows spike (D86): a stand-in for the HAQ print bridge (D87). It listens on port 9100 as the bridge
# will, answers the server's status query (DLE EOT) with "ready", and keeps each receipt's bytes in the
# spike's receipts folder instead of printing them. It proves the hidden server reaches a program on its own
# PC. haq-spike.ps1 runs it as a start-up task; nobody runs it by hand.
$ErrorActionPreference = 'Stop'
$root = Split-Path -Parent $MyInvocation.MyCommand.Path
$log = Join-Path $root 'logs\bridge.log'

$listener = New-Object System.Net.Sockets.TcpListener([System.Net.IPAddress]::Any, 9100)
$listener.Start()
Add-Content -Path $log -Value "$(Get-Date -Format o) listening on port 9100"

while ($true) {
    $client = $listener.AcceptTcpClient()
    try {
        $client.ReceiveTimeout = 5000
        $stream = $client.GetStream()
        $buffer = New-Object byte[] 65536
        $received = New-Object System.IO.MemoryStream
        while ($true) {
            try {
                $count = $stream.Read($buffer, 0, $buffer.Length)
            } catch {
                break
            }
            if ($count -le 0) {
                break
            }
            $received.Write($buffer, 0, $count)
            $bytes = $received.ToArray()
            # The status query, DLE EOT 4 then DLE EOT 2, waits for one byte each: 0x12 says paper in, cover shut.
            if ($bytes.Length -eq 6 -and $bytes[0] -eq 0x10 -and $bytes[1] -eq 0x04 -and $bytes[3] -eq 0x10 -and $bytes[4] -eq 0x04) {
                $stream.Write([byte[]](0x12, 0x12), 0, 2)
                $stream.Flush()
            }
        }
        $data = $received.ToArray()
        if ($data.Length -gt 6) {
            $name = 'receipt-' + (Get-Date -Format 'yyyyMMdd-HHmmss-fff') + '.bin'
            [System.IO.File]::WriteAllBytes((Join-Path $root "receipts\$name"), $data)
        }
        Add-Content -Path $log -Value "$(Get-Date -Format o) $($client.Client.RemoteEndPoint) sent $($data.Length) bytes"
    } catch {
        Add-Content -Path $log -Value "$(Get-Date -Format o) $($_.Exception.Message)"
    } finally {
        $client.Close()
    }
}
