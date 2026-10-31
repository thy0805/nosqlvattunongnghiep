param([ValidateSet('Start','Stop','Check','Build')][string]$Action='Check')
$ErrorActionPreference='Stop'
$q=[IO.Path]::GetFullPath((Join-Path $PSScriptRoot '..'))
if($q -ne 'C:\Hoctap\NoSQL\QA\agrovia-phase3-20261030'){throw 'QA_ROOT_REJECTED'}
$main='C:\Hoctap\NoSQL\Doan\sourcecode'
$node=Join-Path $main 'runtime\node\node.exe'
$php=Join-Path $main 'runtime\php\php.exe'
$ini=Join-Path $main 'state\php.ini'
$env:NEXT_PUBLIC_API_URL='http://localhost:8020'
$env:API_URL='http://localhost:8020'
$env:NEXT_PUBLIC_WEB_URL='http://localhost:3320'
$env:NEXT_TELEMETRY_DISABLED='1'
$env:NODE_ENV='production'
$env:NO_PROXY='localhost,127.0.0.1'
$env:NODE_OPTIONS='--dns-result-order=ipv4first'
$env:PATH=(Join-Path $main 'runtime\node')+';'+$env:SystemRoot+'\System32;'+$env:SystemRoot
$pids=Join-Path $q 'state\processes.json'
function Read-Rows {if(Test-Path $pids){@((Get-Content $pids -Raw|ConvertFrom-Json).processes)}}
function Owned($row){$p=Get-CimInstance Win32_Process -Filter ('ProcessId='+[int]$row.processId) -ErrorAction SilentlyContinue;if(-not $p){return $null};if(([datetime]$row.created).ToUniversalTime().Ticks -ne $p.CreationDate.ToUniversalTime().Ticks -or $p.ExecutablePath -ine $row.exe -or -not $p.CommandLine.Contains($row.marker)){throw 'QA_PROCESS_IDENTITY_REJECTED'};return $p}
if($Action -eq 'Build'){
 if(@(Get-NetTCPConnection -State Listen -LocalPort 3320 -ErrorAction SilentlyContinue).Count){throw 'STOP_QA_WEB_BEFORE_BUILD'}
 Set-Location (Join-Path $q 'web-quan-ly-ban-hang')
 & $node 'node_modules/next/dist/bin/next' build --webpack *> (Join-Path $q 'state\logs\build.log')
 if($LASTEXITCODE -ne 0){throw 'QA_BUILD_FAILED'}
 Write-Output 'QA build passed';exit
}
if($Action -eq 'Stop'){
 foreach($row in @(Read-Rows)){if(Owned $row){Stop-Process -Id $row.processId -ErrorAction Stop}}
 @{processes=@()}|ConvertTo-Json|Set-Content $pids -Encoding utf8
 Write-Output 'Stopped QA API/Web only; Mongo and main left running';exit
}
if($Action -eq 'Start'){
 $rows=@(Read-Rows)
 foreach($spec in @(@{name='api';port=8020;exe=$php;args=@('-c',$ini,'-S','127.0.0.1:8020','-t',(Join-Path $q 'api-laravel\public'),(Join-Path $q 'scripts\api-router.php'));marker=(Join-Path $q 'scripts\api-router.php')},@{name='web';port=3320;exe=$node;args=@((Join-Path $q 'scripts\web-server.cjs'));marker=(Join-Path $q 'scripts\web-server.cjs')})){
  $live=@($rows|Where-Object name -eq $spec.name|Where-Object{Owned $_});$listeners=@(Get-NetTCPConnection -State Listen -LocalPort $spec.port -ErrorAction SilentlyContinue)
  if($live.Count){if($listeners.Count -ne 1 -or $listeners[0].OwningProcess -ne $live[0].processId){throw 'QA_PORT_OWNER_REJECTED'};continue}
  if($listeners.Count){throw 'QA_PORT_BUSY_NO_KILL'}
  $rows=@($rows|Where-Object name -ne $spec.name)
  $arguments=($spec.args|ForEach-Object{'"'+$_+'"'}) -join ' '
  $process=Start-Process $spec.exe -ArgumentList $arguments -WorkingDirectory $q -WindowStyle Hidden -RedirectStandardOutput (Join-Path $q ('state\logs\'+$spec.name+'.out.log')) -RedirectStandardError (Join-Path $q ('state\logs\'+$spec.name+'.err.log')) -PassThru
  Start-Sleep -Milliseconds 500
  $p=Get-CimInstance Win32_Process -Filter ('ProcessId='+$process.Id)
  if(-not $p){throw 'QA_PROCESS_EXITED'}
  $rows+=[pscustomobject]@{name=$spec.name;port=$spec.port;processId=$process.Id;created=$p.CreationDate.ToUniversalTime().ToString('o');exe=$spec.exe;marker=$spec.marker}
  @{processes=@($rows)}|ConvertTo-Json -Depth 5|Set-Content $pids -Encoding utf8
 }
}
foreach($row in @(Read-Rows)){if(-not (Owned $row)){throw 'QA_NOT_RUNNING'};$listener=@(Get-NetTCPConnection -State Listen -LocalPort $row.port -ErrorAction SilentlyContinue);if($listener.Count -ne 1 -or $listener[0].LocalAddress -ne '127.0.0.1' -or $listener[0].OwningProcess -ne $row.processId){throw 'QA_BIND_REJECTED'}}
Write-Output 'QA loopback API8020/Web3320; main ports untouched'
