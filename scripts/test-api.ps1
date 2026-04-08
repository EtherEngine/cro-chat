$payload = @{ email = 'heather@example.com'; password = 'password' }
$json = $payload | ConvertTo-Json
Write-Output "SENDING: $json"
try {
    $r = Invoke-RestMethod -Uri 'http://localhost/chat-api/public/api/auth/login' -Method POST -ContentType 'application/json; charset=utf-8' -Body ([System.Text.Encoding]::UTF8.GetBytes($json)) -SessionVariable sess
    Write-Output "LOGIN OK:"
    $r | ConvertTo-Json -Depth 5
    
    # Test /auth/me
    $r2 = Invoke-RestMethod -Uri 'http://localhost/chat-api/public/api/auth/me' -Method GET -WebSession $sess
    Write-Output "`nME OK:"
    $r2 | ConvertTo-Json -Depth 5

    # Test spaces
    $r3 = Invoke-RestMethod -Uri 'http://localhost/chat-api/public/api/spaces' -Method GET -WebSession $sess
    Write-Output "`nSPACES OK:"
    $r3 | ConvertTo-Json -Depth 5

    # Test channels for space 1
    $r4 = Invoke-RestMethod -Uri 'http://localhost/chat-api/public/api/spaces/1/channels' -Method GET -WebSession $sess
    Write-Output "`nCHANNELS OK:"
    $r4 | ConvertTo-Json -Depth 5

    # Test channel messages
    $r5 = Invoke-RestMethod -Uri 'http://localhost/chat-api/public/api/channels/1/messages' -Method GET -WebSession $sess
    Write-Output "`nMESSAGES OK:"
    $r5 | ConvertTo-Json -Depth 5

    # Test conversations (DMs)
    $r6 = Invoke-RestMethod -Uri 'http://localhost/chat-api/public/api/conversations' -Method GET -WebSession $sess
    Write-Output "`nCONVERSATIONS OK:"
    $r6 | ConvertTo-Json -Depth 5

    # Test users
    $r7 = Invoke-RestMethod -Uri 'http://localhost/chat-api/public/api/users' -Method GET -WebSession $sess
    Write-Output "`nUSERS:"
    Write-Output "Count: $(($r7.users).Count)"

    # Test presence heartbeat
    $r8 = Invoke-RestMethod -Uri 'http://localhost/chat-api/public/api/presence/heartbeat' -Method POST -WebSession $sess
    Write-Output "`nHEARTBEAT:"
    $r8 | ConvertTo-Json

    # Test unread counts
    $r9 = Invoke-RestMethod -Uri 'http://localhost/chat-api/public/api/unread' -Method GET -WebSession $sess
    Write-Output "`nUNREAD:"
    $r9 | ConvertTo-Json -Depth 5

}
catch {
    Write-Output "ERROR: $($_.Exception.Message)"
    try {
        $stream = $_.Exception.Response.GetResponseStream()
        $reader = New-Object System.IO.StreamReader($stream)
        $body = $reader.ReadToEnd()
        Write-Output "Response Body: $body"
    }
    catch {
        Write-Output "Could not read response body"
    }
}
