<?php

namespace App\Infrastructure\Mongo;

use RuntimeException;

final class Isolation
{
    public static function validate(array $settings): array
    {
        $reject = static function (): never {
            throw new RuntimeException('NOSQL_ISOLATION_REJECTED');
        };
        $database = $settings['database'] ?? '';
        if ($database !== 'nosql186_web_dev' && !preg_match('/^nosql186_web_test_[a-z0-9_]{8,48}$/D', $database)) {
            $reject();
        }
        $qa = ($settings['app_url'] ?? '') === 'http://localhost:8020';
        $qaDatabase = 'nosql186_web_test_agrovia_20261030_qa';
        $web = $qa ? 'http://localhost:3320' : 'http://nosql.localhost:3310';
        $cookie = $qa ? 'nosql186_qa_api_session' : 'nosql186_api_session';
        if (($qa && $database !== $qaDatabase) || (!$qa && $database === $qaDatabase)) {
            $reject();
        }
        if ($qa && str_replace('\\', '/', $settings['session_files'] ?? '') !== 'C:/Hoctap/NoSQL/QA/agrovia-phase3-20261030/api-laravel/storage/framework/sessions') {
            $reject();
        }
        $uri = $settings['uri'] ?? '';
        if (!is_string($uri) || !preg_match('~^mongodb://[^/]+/[^/?]+\?[^#]+$~D', $uri)) {
            $reject();
        }
        $parts = parse_url($uri);
        if (!$parts || ($parts['scheme'] ?? '') !== 'mongodb' || ($parts['host'] ?? '') !== 'localhost'
            || ($parts['port'] ?? 0) !== 27028 || ($parts['path'] ?? '') !== '/'.$database
            || empty($parts['user']) || empty($parts['pass']) || isset($parts['fragment'])) {
            $reject();
        }
        parse_str($parts['query'] ?? '', $query);
        $expected = ['authSource' => $database, 'replicaSet' => 'nosql186_rs', 'readPreference' => 'primary', 'retryWrites' => 'true'];
        ksort($expected);
        ksort($query);
        if ($query !== $expected || count(explode('&', $parts['query'])) !== count($expected)) {
            $reject();
        }
        if (!in_array(rawurldecode($parts['user']), ['nosql186_app', 'nosql186_worker', 'nosql186_schema'], true)
            || strlen(rawurldecode($parts['pass'])) < 32
            || ($settings['connection'] ?? '') !== 'mongodb'
            || ($settings['replica_set'] ?? '') !== 'nosql186_rs'
            || ($settings['app_url'] ?? '') !== ($qa ? 'http://localhost:8020' : 'http://nosql.localhost:8010')
            || ($settings['web_origin'] ?? '') !== $web
            || ($settings['session_cookie'] ?? '') !== $cookie
            || ($settings['session_driver'] ?? '') !== 'file'
            || !in_array($settings['session_domain'] ?? null, [null, '', 'null'], true)
            || ($settings['cache_store'] ?? '') !== 'file'
            || ($settings['queue_connection'] ?? '') !== 'sync'
            || !preg_match('~^base64:[A-Za-z0-9+/]{43}=$~D', $settings['app_key'] ?? '')
            || !empty($settings['sql_url'])) {
            $reject();
        }
        return $settings;
    }
}
