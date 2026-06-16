$excelPath = "C:\Users\ASUS\OneDrive\Escritorio\Claude\Actualizaciones.xlsx"
$outPath = "c:\wamp64\www\empresa\admin-api\database\seeders\data\actualizaciones_excel.json"

function Normalize-Version($v) { $v = $v.Trim(); if ($v -match '^v') { return $v.Substring(1) } return $v }
function Is-Green($ci) { return ($ci -eq 4 -or $ci -eq 15 -or $ci -eq 22) }

function Slug-FromExcelClient($name) {
    $map = @{
        'Servian'='servian'; 'masquito'='masquito'; 'sanblas'='sanblas'; '2r'='2r'; 'ferretotal'='ferretotal'; 'trama'='trama'
        'Golden Breeze'='golden-breeze'; 'Leudinox'='leudinox'; 'panchito'='panchito'; 'Distri-Creo'='distri-creo'; 'GoloNorte'='golonorte'
        'Innovate'='innovate'; 'Rober'='rober'; 'San Cayetano'='san-cayetano'; 'San-cayetano'='san-cayetano'; 'Truvari'='truvari'
        'La Martina'='lamartina'; 'LaMartina'='lamartina'; 'Arfren'='arfren'; 'EMPRESA'='empresa'
        'Empresa - HiperMax'='hipermax'; 'Empresa - Fenix'='fenix'; 'Fenix'='fenix'; 'Empresa - Galvan'='galvan'
        'Galvan Matias'='galvan'; 'CF'='cf'; 'CF2'='cf'; 'ChevroCar'='chevrocar'; '3dtisk'='3dtisk'; 'Oliva'='oliva'
        'FFPerformance'='ffperformance'; 'HT5'='ht5'; 'MbMalizia'='mbmalizia'; 'Ananda'='ananda'
        'FerreMas'='ferremas'; 'Lacarra'='lacarra'; 'DEMO'='demo'; 'DEMO2'='demo2'; 'HBDistribuc'='hb'; 'HBDistribuciones'='hb'
    }
    if ($map.ContainsKey($name)) { return $map[$name] }
    return ($name.ToLower() -replace '\s+','-')
}

function Extract-SeederClass($cmd) {
    if ($cmd -match '--class=([^\s]+)') { return $Matches[1] }
    return $null
}

function First-Line-Title($text) {
    $line = ($text -split "`n")[0].Trim()
    $line = $line -replace '^[\?\s\*]+',''
    $line = $line -replace '\*',''
    return $line.Trim()
}

$excel = New-Object -ComObject Excel.Application
$excel.Visible = $false
$excel.DisplayAlerts = $false
$wb = $excel.Workbooks.Open($excelPath)

$result = @{ versions = @{}; current_versions = @{} }

$ws = $wb.Sheets.Item('Versiones')
for ($r=2; $r -le 30; $r++) {
    $ver = Normalize-Version ($ws.Cells.Item($r,1).Text)
    if (-not $ver) { continue }
    $desc = $ws.Cells.Item($r,2).Text.Trim()
    if (-not $result.versions.ContainsKey($ver)) {
        $result.versions[$ver] = @{ description=$desc; seeders=@(); commands=@(); manual_tasks=@(); notifications=@() }
    }
}

$ws = $wb.Sheets.Item('Seeders')
$lastRow = $ws.UsedRange.Rows.Count
for ($r=3; $r -le $lastRow; $r++) {
    $ver = Normalize-Version ($ws.Cells.Item($r,1).Text.Trim())
    $cmd = $ws.Cells.Item($r,2).Text.Trim()
    if (-not $ver -or -not $cmd) { continue }
    if (-not $result.versions.ContainsKey($ver)) {
        $result.versions[$ver] = @{description='';seeders=@();commands=@();manual_tasks=@();notifications=@()}
    }
    $bg = $ws.Cells.Item($r,2).Interior.ColorIndex
    $scope = if (Is-Green $bg) { 'per_user' } else { 'per_database' }
    $class = Extract-SeederClass $cmd
    if ($class) {
        $result.versions[$ver].seeders += @{ seeder_class=$class; run_scope=$scope }
    } else {
        $result.versions[$ver].manual_tasks += @{ title=$cmd; description=$cmd }
    }
}

$ws = $wb.Sheets.Item('Comandos')
$lastRow = $ws.UsedRange.Rows.Count
for ($r=3; $r -le $lastRow; $r++) {
    $ver = Normalize-Version ($ws.Cells.Item($r,1).Text.Trim())
    $cmd = $ws.Cells.Item($r,2).Text.Trim()
    if (-not $ver -or -not $cmd) { continue }
    if (-not $result.versions.ContainsKey($ver)) {
        $result.versions[$ver] = @{description='';seeders=@();commands=@();manual_tasks=@();notifications=@()}
    }
    $bg = $ws.Cells.Item($r,2).Interior.ColorIndex
    $scope = if (Is-Green $bg) { 'per_database' } else { 'per_user' }
    if ($cmd -match '^php artisan') {
        $result.versions[$ver].commands += @{ command=$cmd; run_scope=$scope }
    } else {
        $result.versions[$ver].manual_tasks += @{ title=$cmd; description=$cmd }
    }
}

$ws = $wb.Sheets.Item('Notificaciones')
$lastRow = $ws.UsedRange.Rows.Count
$lastCol = $ws.UsedRange.Columns.Count
$notifHeaders = @{}
for ($c=3; $c -le $lastCol; $c++) {
    $h = $ws.Cells.Item(1,$c).Text.Trim()
    if ($h) { $notifHeaders[$c] = $h }
}
for ($r=2; $r -le $lastRow; $r++) {
    $ver = Normalize-Version ($ws.Cells.Item($r,1).Text.Trim())
    if (-not $ver) { continue }
    if (-not $result.versions.ContainsKey($ver)) {
        $result.versions[$ver] = @{description='';seeders=@();commands=@();manual_tasks=@();notifications=@()}
    }
    $all = $ws.Cells.Item($r,2).Text.Trim()
    if ($all) {
        $result.versions[$ver].notifications += @{ title=(First-Line-Title $all); body=$all; restricted_to_client_slug=$null }
    }
    foreach ($c in $notifHeaders.Keys) {
        $val = $ws.Cells.Item($r,$c).Text.Trim()
        if ($val) {
            $slug = Slug-FromExcelClient $notifHeaders[$c]
            $result.versions[$ver].notifications += @{ title=(First-Line-Title $val); body=$val; restricted_to_client_slug=$slug }
        }
    }
}

$clientMax = @{}
$sheets = @(@{Name='Seeders';StartCol=4},@{Name='Comandos';StartCol=3},@{Name='Notificaciones';StartCol=3})
foreach ($sheet in $sheets) {
    $ws = $wb.Sheets.Item($sheet.Name)
    $lastRow = $ws.UsedRange.Rows.Count
    $lastCol = $ws.UsedRange.Columns.Count
    $headers = @{}
    for ($c=$sheet.StartCol; $c -le $lastCol; $c++) {
        $h = $ws.Cells.Item(1,$c).Text.Trim()
        if ($h) { $headers[$c] = $h }
    }
    for ($r=2; $r -le $lastRow; $r++) {
        $ver = Normalize-Version ($ws.Cells.Item($r,1).Text.Trim())
        if (-not $ver) { continue }
        foreach ($c in $headers.Keys) {
            $cell = $ws.Cells.Item($r,$c)
            $val = $cell.Text.Trim()
            if (-not $val) { continue }
            if ($cell.Interior.ColorIndex -eq 4) {
                $slug = Slug-FromExcelClient $headers[$c]
                if (-not $clientMax.ContainsKey($slug) -or ([version]$ver -gt [version]$clientMax[$slug])) {
                    $clientMax[$slug] = $ver
                }
            }
        }
    }
}
$result.current_versions = $clientMax

$json = $result | ConvertTo-Json -Depth 12
$utf8_no_bom = New-Object System.Text.UTF8Encoding $false
[System.IO.File]::WriteAllText($outPath, $json, $utf8_no_bom)
Write-Host "Exported. Versions: $($result.versions.Keys -join ', ')"
Write-Host "Current versions count: $($clientMax.Count)"
$wb.Close($false)
$excel.Quit()
