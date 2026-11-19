[CmdletBinding()]
param(
    [string]$ZipPath = (Join-Path (Get-Location) "ibge\DTB_2025.zip"),
    [string]$OutputPath = (Join-Path (Get-Location) "database\data\ibge\municipios_dtb_2025.csv")
)

$ErrorActionPreference = "Stop"

if (-not (Test-Path -LiteralPath $ZipPath -PathType Leaf)) {
    throw "Arquivo DTB_2025.zip não encontrado: $ZipPath"
}

Add-Type -AssemblyName System.IO.Compression.FileSystem
$archive = [System.IO.Compression.ZipFile]::OpenRead((Resolve-Path -LiteralPath $ZipPath).Path)

try {
    $entry = $archive.GetEntry("RELATORIO_DTB_BRASIL_2025_MUNICIPIOS.ods")
    if ($null -eq $entry) {
        throw "A planilha RELATORIO_DTB_BRASIL_2025_MUNICIPIOS.ods não foi encontrada no ZIP."
    }

    $stream = $entry.Open()
    try {
        $reader = [System.IO.StreamReader]::new($stream)
        try {
            [xml]$document = $reader.ReadToEnd()
        }
        finally {
            $reader.Dispose()
        }
    }
    finally {
        $stream.Dispose()
    }
}
finally {
    $archive.Dispose()
}

$namespaces = [System.Xml.XmlNamespaceManager]::new($document.NameTable)
$namespaces.AddNamespace("table", "urn:oasis:names:tc:opendocument:xmlns:table:1.0")
$namespaces.AddNamespace("text", "urn:oasis:names:tc:opendocument:xmlns:text:1.0")
$namespaces.AddNamespace("office", "urn:oasis:names:tc:opendocument:xmlns:office:1.0")

$sheet = $document.SelectSingleNode("//table:table[@table:name='DTB_Municípios']", $namespaces)
if ($null -eq $sheet) {
    throw "A aba DTB_Municípios não foi encontrada na planilha."
}

$baseDateFound = $document.SelectNodes("//text:p", $namespaces) |
    Where-Object { $_.InnerText -match 'DATA BASE:\s*31/12/2025' } |
    Select-Object -First 1
if ($null -eq $baseDateFound) {
    throw "A planilha não identifica a data-base oficial 31/12/2025."
}

$rows = $sheet.SelectNodes("./table:table-row", $namespaces)
$header = $null
$records = [System.Collections.Generic.List[object]]::new()
$seen = [System.Collections.Generic.HashSet[string]]::new([System.StringComparer]::Ordinal)

foreach ($row in $rows) {
    $cells = [System.Collections.Generic.List[string]]::new()

    foreach ($cell in $row.SelectNodes("./table:table-cell | ./table:covered-table-cell", $namespaces)) {
        $value = (($cell.SelectNodes(".//text:p", $namespaces) | ForEach-Object { $_.InnerText }) -join " ").Trim()
        $repeat = 1
        $repeatAttribute = $cell.GetAttribute("number-columns-repeated", "urn:oasis:names:tc:opendocument:xmlns:table:1.0")
        if ($repeatAttribute) {
            $repeat = [int]$repeatAttribute
        }

        for ($i = 0; $i -lt $repeat; $i++) {
            $cells.Add($value)
        }
    }

    if ($null -eq $header) {
        if ($cells.Count -ge 9 -and $cells[0] -eq "UF" -and $cells[7] -eq "Código Município Completo") {
            $header = $true
        }
        continue
    }

    if ($cells.Count -lt 9) {
        continue
    }

    $ufCode = $cells[0].Trim()
    $intermediateCode = $cells[2].Trim()
    $intermediateName = $cells[3].Trim()
    $immediateCode = $cells[4].Trim()
    $immediateName = $cells[5].Trim()
    $municipalityCode = $cells[7].Trim()
    $municipalityName = $cells[8].Trim()

    if (-not $municipalityCode -or -not $municipalityName) {
        continue
    }

    if ($municipalityCode -notmatch '^\d{7}$' -or $ufCode -notmatch '^\d{2}$') {
        throw "Código IBGE inválido na planilha: município '$municipalityCode', UF '$ufCode'."
    }

    if (-not $seen.Add($municipalityCode)) {
        throw "Código de município duplicado na DTB 2025: $municipalityCode."
    }

    if (-not $intermediateCode -or -not $intermediateName -or -not $immediateCode -or -not $immediateName) {
        throw "Região imediata/intermediária ausente para o município $municipalityCode - $municipalityName."
    }

    $records.Add([pscustomobject]@{
        codigo_ibge = $municipalityCode
        nome = $municipalityName
        codigo_uf = $ufCode
        intermediate_region_code = $intermediateCode
        intermediate_region_name = $intermediateName
        immediate_region_code = $immediateCode
        immediate_region_name = $immediateName
    })
}

if ($records.Count -lt 5000) {
    throw "Quantidade inesperada de municípios na DTB 2025: $($records.Count). Nenhum CSV foi gerado."
}

$directory = Split-Path -Parent $OutputPath
New-Item -ItemType Directory -Path $directory -Force | Out-Null
$records | Export-Csv -LiteralPath $OutputPath -NoTypeInformation -Encoding UTF8

Write-Host "Data-base: 31/12/2025"
Write-Host "Municípios exportados: $($records.Count)"
Write-Host "Códigos duplicados: 0"
Write-Host "Arquivo gerado: $((Resolve-Path -LiteralPath $OutputPath).Path)"
