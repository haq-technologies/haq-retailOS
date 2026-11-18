<#
Fails when a function in a PowerShell script assigns a variable with the name of one of the script's own (its
parameters, or what it assigns outside any function), whatever the case. PowerShell's names ignore case, so the
function's variable hides the script's: in the Windows spike, $inside (a file's text) hid $Inside (that file's
path inside WSL), and its first real run failed (18 November 2026).

  pwsh tools/ci/powershell-names.ps1 apps/windows
#>
param([Parameter(Mandatory = $true)] [string] $Path)

$ErrorActionPreference = 'Stop'
$failed = $false

function Test-InFunction($Node) {
    for ($p = $Node.Parent; $null -ne $p; $p = $p.Parent) {
        if ($p -is [System.Management.Automation.Language.FunctionDefinitionAst]) { return $true }
    }
    return $false
}

foreach ($file in Get-ChildItem $Path -Recurse -Filter *.ps1) {
    $tree = [System.Management.Automation.Language.Parser]::ParseFile($file.FullName, [ref]$null, [ref]$null)
    $found = $tree.FindAll({
            param($n)
            ($n -is [System.Management.Automation.Language.AssignmentStatementAst] -and
                $n.Left -is [System.Management.Automation.Language.VariableExpressionAst]) -or
            $n -is [System.Management.Automation.Language.ForEachStatementAst]
        }, $true)
    $named = foreach ($n in $found) {
        if ($n -is [System.Management.Automation.Language.ForEachStatementAst]) { $variable = $n.Variable } else { $variable = $n.Left }
        if ($variable.VariablePath.IsUnqualified) {
            [pscustomobject]@{
                Name = $variable.VariablePath.UserPath.ToLowerInvariant()
                Line = $n.Extent.StartLineNumber
                InFunction = Test-InFunction $n
            }
        }
    }
    $own = @($named | Where-Object { -not $_.InFunction } | ForEach-Object { $_.Name })
    if ($tree.ParamBlock) { $own += @($tree.ParamBlock.Parameters | ForEach-Object { $_.Name.VariablePath.UserPath.ToLowerInvariant() }) }
    foreach ($clash in @($named | Where-Object { $_.InFunction -and $own -contains $_.Name })) {
        Write-Host ('{0}:{1}: a function assigns ${2}, which hides the script''s own variable of that name' -f $file.FullName, $clash.Line, $clash.Name)
        $failed = $true
    }
}
if ($failed) { exit 1 }
Write-Host 'No function hides a script-level variable.'
