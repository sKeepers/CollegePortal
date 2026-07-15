@{
    Severity = @('Error', 'Warning')
    IncludeRules = @(
        'PSUseCompatibleSyntax',
        'PSUseCompatibleCmdlets'
    )
    Rules = @{
        PSUseCompatibleSyntax = @{
            Enable = $true
            TargetVersions = @('2.0')
        }
        PSUseCompatibleCmdlets = @{
            compatibility = @('desktop-2.0-windows')
        }
    }
}
