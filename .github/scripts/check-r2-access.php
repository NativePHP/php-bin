<?php

/*
 * check-r2-access.php
 *
 * Checks that a pair of R2 keys can do everything the php-bin publish job
 * does in the nativephplibs bucket: list objects, upload (both a plain PUT
 * and a multipart upload, which `aws s3 cp` uses for the ~25 MB zips), read
 * the object back and delete it again.
 *
 * Usage:
 *   php .github/scripts/check-r2-access.php
 *
 * You're prompted for the keys (the secret isn't echoed). To skip the prompts,
 * set R2_ACCESS_KEY_ID and R2_SECRET_ACCESS_KEY in the environment. R2_ENDPOINT
 * and R2_BUCKET default to the values the workflows use.
 *
 * The keys are never printed. The only thing written to the bucket is one
 * small object under _access-check/, which is deleted at the end.
 *
 * Exits 0 when every required check passes, 1 otherwise.
 */

const DEFAULT_ENDPOINT = 'https://713f4e1d515cf082921cdf5122bf1739.r2.cloudflarestorage.com';
const DEFAULT_BUCKET = 'nativephplibs';
const REGION = 'auto';

/**
 * AWS Signature Version 4 for S3-style requests. Signs every header passed in.
 */
function sigv4_signature(
    string $method,
    string $path,
    array $query,
    array $headers,
    string $payloadHash,
    string $amzDate,
    string $region,
    string $secret,
): array {
    ksort($query);
    $canonicalQuery = implode('&', array_map(
        fn ($k, $v) => rawurlencode($k).'='.rawurlencode($v),
        array_keys($query),
        $query,
    ));

    $lower = [];
    foreach ($headers as $name => $value) {
        $lower[strtolower($name)] = trim($value);
    }
    ksort($lower);
    $canonicalHeaders = '';
    foreach ($lower as $name => $value) {
        $canonicalHeaders .= "{$name}:{$value}\n";
    }
    $signedHeaders = implode(';', array_keys($lower));

    $canonicalRequest = implode("\n", [
        $method,
        $path,
        $canonicalQuery,
        $canonicalHeaders,
        $signedHeaders,
        $payloadHash,
    ]);

    $date = substr($amzDate, 0, 8);
    $scope = "{$date}/{$region}/s3/aws4_request";
    $stringToSign = implode("\n", [
        'AWS4-HMAC-SHA256',
        $amzDate,
        $scope,
        hash('sha256', $canonicalRequest),
    ]);

    $key = hash_hmac('sha256', $date, 'AWS4'.$secret, true);
    $key = hash_hmac('sha256', $region, $key, true);
    $key = hash_hmac('sha256', 's3', $key, true);
    $key = hash_hmac('sha256', 'aws4_request', $key, true);

    return [
        'signature' => hash_hmac('sha256', $stringToSign, $key),
        'scope' => $scope,
        'signed_headers' => $signedHeaders,
    ];
}

/**
 * Send one signed request to R2. Returns [status, body, error code].
 */
function r2_request(array $cfg, string $method, string $key = '', array $query = [], string $body = ''): array
{
    $host = parse_url($cfg['endpoint'], PHP_URL_HOST);
    $path = '/'.$cfg['bucket'];
    if ($key !== '') {
        $path .= '/'.implode('/', array_map('rawurlencode', explode('/', $key)));
    }

    $amzDate = gmdate('Ymd\THis\Z');
    $payloadHash = hash('sha256', $body);
    $headers = [
        'host' => $host,
        'x-amz-content-sha256' => $payloadHash,
        'x-amz-date' => $amzDate,
    ];

    $sig = sigv4_signature($method, $path, $query, $headers, $payloadHash, $amzDate, REGION, $cfg['secret']);
    $headers['authorization'] = sprintf(
        'AWS4-HMAC-SHA256 Credential=%s/%s, SignedHeaders=%s, Signature=%s',
        $cfg['id'], $sig['scope'], $sig['signed_headers'], $sig['signature'],
    );

    $url = 'https://'.$host.$path;
    if ($query) {
        ksort($query);
        $url .= '?'.http_build_query($query, '', '&', PHP_QUERY_RFC3986);
    }

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_CUSTOMREQUEST => $method,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_NOBODY => $method === 'HEAD',
        CURLOPT_HTTPHEADER => array_map(fn ($k, $v) => "{$k}: {$v}", array_keys($headers), $headers),
        CURLOPT_TIMEOUT => 30,
    ]);
    if ($method === 'PUT' || $method === 'POST') {
        curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
    }

    $response = curl_exec($ch);
    if ($response === false) {
        $error = curl_error($ch);
        curl_close($ch);

        return [0, '', 'network error: '.$error];
    }
    $status = curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    curl_close($ch);

    $code = null;
    if ($status >= 300) {
        $code = preg_match('#<Code>(.*?)</Code>#', (string) $response, $m) ? $m[1] : "HTTP {$status}";
    }

    return [$status, (string) $response, $code];
}

function hint(string $code, array $cfg): string
{
    return match (true) {
        $code === 'AccessDenied' || $code === 'HTTP 403' => "The token isn't allowed to do this in '{$cfg['bucket']}'. In Cloudflare > R2 > "
            ."Manage API tokens, give it Object Read & Write and include '{$cfg['bucket']}' (or all buckets). "
            ."If it already has that, it was probably created under a different account from {$cfg['account']}.",
        $code === 'Unauthorized' || $code === 'InvalidAccessKeyId' => "R2 doesn't recognise these keys on account {$cfg['account']}. "
            .'Either the Access Key ID is wrong, the token was deleted or rolled, or it belongs to another account.',
        $code === 'SignatureDoesNotMatch' => "R2 knows the Access Key ID, but the secret doesn't belong to it. Both values have to come from the same token.",
        $code === 'NoSuchBucket' => "There's no bucket called '{$cfg['bucket']}' on account {$cfg['account']}.",
        default => '',
    };
}

function prompt(string $label, bool $hidden = false): string
{
    fwrite(STDERR, $label);
    $tty = function_exists('stream_isatty') && stream_isatty(STDIN);
    if ($hidden && $tty) {
        shell_exec('stty -echo');
    }
    $value = rtrim((string) fgets(STDIN), "\r\n");
    if ($hidden && $tty) {
        shell_exec('stty echo');
        fwrite(STDERR, PHP_EOL);
    }

    return $value;
}

function main(): int
{
    $id = getenv('R2_ACCESS_KEY_ID') ?: prompt('R2 Access Key ID: ');
    $secret = getenv('R2_SECRET_ACCESS_KEY') ?: prompt('R2 Secret Access Key (hidden): ', hidden: true);
    $endpoint = getenv('R2_ENDPOINT') ?: DEFAULT_ENDPOINT;

    $cfg = [
        'id' => $id,
        'secret' => $secret,
        'endpoint' => rtrim($endpoint, '/'),
        'bucket' => getenv('R2_BUCKET') ?: DEFAULT_BUCKET,
        'account' => explode('.', (string) parse_url($endpoint, PHP_URL_HOST))[0],
    ];

    echo "Endpoint: {$cfg['endpoint']}\nBucket:   {$cfg['bucket']}\n\n";

    $failed = false;
    $pass = function (string $label, string $detail = '') {
        echo "  ✓ {$label}".($detail !== '' ? " ({$detail})" : '')."\n";
    };
    $fail = function (string $label, string $detail) use (&$failed, $cfg) {
        $failed = true;
        echo "  ✗ {$label}: {$detail}\n";
        if ($hint = hint($detail, $cfg)) {
            echo '      '.wordwrap($hint, 74, "\n      ")."\n";
        }
    };

    // R2 S3 keys are always 32 and 64 hex characters. Anything else usually
    // means a Cloudflare API token (or the token's ID) went into the wrong box.
    echo "Key format\n";
    foreach ([['Access Key ID', $id, 32], ['Secret Access Key', $secret, 64]] as [$label, $value, $want]) {
        if ($value !== trim($value)) {
            $fail($label, 'has leading or trailing whitespace, which GitHub would keep too');
        } elseif (strlen($value) !== $want || ! ctype_xdigit($value)) {
            $fail($label, sprintf('is %d characters, expected %d hex characters', strlen($value), $want));
        } else {
            $pass($label, "{$want} hex characters");
        }
    }
    if ($failed) {
        echo "\nFix the key format first; R2 would reject these before checking permissions.\n";

        return 1;
    }

    // Informational only: tokens scoped to particular buckets can't list
    // buckets, which is fine. If it can, this shows which account it's on.
    echo "\nAccount\n";
    [$status, $body, $code] = r2_request(array_merge($cfg, ['bucket' => '']), 'GET');
    if ($code === null) {
        preg_match_all('#<Name>(.*?)</Name>#', $body, $m);
        $names = $m[1];
        $in = in_array($cfg['bucket'], $names, true);
        echo '  '.($in ? '✓' : '!')." Token can list buckets on this account: ".(implode(', ', $names) ?: 'none')."\n";
        if (! $in) {
            echo "      '{$cfg['bucket']}' isn't among them, so this token is probably for another account.\n";
        }
    } elseif ($code === 'AccessDenied') {
        echo "  - Can't list buckets. Normal for a token scoped to specific buckets.\n";
    } else {
        echo "  ! Can't list buckets ({$code})\n";
        if ($hint = hint($code, $cfg)) {
            echo '      '.wordwrap($hint, 74, "\n      ")."\n";
        }
    }

    echo "\nWhat the publish job needs\n";
    $key = '_access-check/php-bin-'.gmdate('Ymd-His').'-'.bin2hex(random_bytes(3)).'.txt';

    [$status, $body, $code] = r2_request($cfg, 'GET', '', ['list-type' => '2', 'prefix' => 'main/desktop/', 'max-keys' => '5']);
    if ($code === null) {
        $found = preg_match_all('#<Key>#', $body);
        $pass('List objects', ($found === 5 ? '5+' : $found).' found under main/desktop/');
    } else {
        $fail('List objects', $code);
    }

    [$status, $body, $code] = r2_request($cfg, 'PUT', $key, [], "php-bin R2 access check\n");
    $put = $code === null;
    $put ? $pass('Upload object', $key) : $fail('Upload object', $code);

    if ($put) {
        [$status, $body, $code] = r2_request($cfg, 'HEAD', $key);
        $code === null ? $pass('Read object back') : $fail('Read object back', $code);
    }

    // aws s3 cp switches to multipart above 8 MB, so the real zips go this way.
    [$status, $body, $code] = r2_request($cfg, 'POST', $key.'.multipart', ['uploads' => '']);
    if ($code === null && preg_match('#<UploadId>(.*?)</UploadId>#', $body, $m)) {
        $pass('Start multipart upload');
        [$status, $body, $code] = r2_request($cfg, 'DELETE', $key.'.multipart', ['uploadId' => html_entity_decode($m[1])]);
        $code === null ? $pass('Abort multipart upload') : $fail('Abort multipart upload', $code);
    } else {
        $fail('Start multipart upload', $code ?? 'no UploadId in response');
    }

    if ($put) {
        [$status, $body, $code] = r2_request($cfg, 'DELETE', $key);
        $code === null
            ? $pass('Delete test object')
            : $fail('Delete test object', "{$code} (left behind: {$key})");
    }

    echo $failed
        ? "\nThese keys won't work for the publish job yet.\n"
        : "\nAll good. Put the same two values in the R2_ACCESS_KEY_ID and R2_SECRET_ACCESS_KEY\nrepository secrets on NativePHP/php-bin.\n";

    return $failed ? 1 : 0;
}

if (PHP_SAPI === 'cli' && realpath($_SERVER['SCRIPT_FILENAME'] ?? '') === __FILE__) {
    exit(main());
}
