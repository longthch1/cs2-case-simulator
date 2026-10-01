<?php
declare(strict_types=1);

namespace CS2\Services;

final class OpenApiService
{
    public static function specification(): array
    {
        $json = function (array $schema): array {
            return [
                'content' => [
                    'application/json' => ['schema' => $schema],
                ],
            ];
        };
        $response = function (string $description = 'OK', ?array $schema = null): array {
            $r = ['description' => $description];
            if ($schema !== null) {
                $r['content'] = ['application/json' => ['schema' => $schema]];
            }
            return $r;
        };

        $paths = [
            '/api/health' => [
                'get' => ['summary' => 'Health check', 'responses' => ['200' => $response('Healthy'), '503' => $response('Database unavailable')]],
            ],
            '/api/cases' => [
                'get' => ['summary' => 'List active cases', 'responses' => ['200' => $response('Case catalog')]],
            ],
            '/api/cases/{case_id}' => [
                'get' => ['summary' => 'Case detail', 'parameters' => [['name'=>'case_id','in'=>'path','required'=>true,'schema'=>['type'=>'string']]], 'responses' => ['200'=>$response('Case detail'),'404'=>$response('Case not found')]],
            ],
            '/api/cases/{case_id}/open' => [
                'post' => ['summary'=>'Open one or more cases','security'=>[['sessionCookie'=>[]]],'parameters'=>[['name'=>'case_id','in'=>'path','required'=>true,'schema'=>['type'=>'string']]],'requestBody'=>$json(['type'=>'object','properties'=>['count'=>['type'=>'integer','minimum'=>1,'maximum'=>10,'default'=>1],'client_seed'=>['type'=>'string','maxLength'=>64]]]),'responses'=>['200'=>$response('Case opening result'),'400'=>$response('Invalid request'),'401'=>$response('Authentication required'),'429'=>$response('Rate limited')]],
            ],
            '/api/cases/verify-roll' => [
                'post' => ['summary'=>'Verify a provably-fair roll','requestBody'=>$json(['type'=>'object','required'=>['server_seed','client_seed','nonce'],'properties'=>['server_seed'=>['type'=>'string'],'client_seed'=>['type'=>'string'],'nonce'=>['type'=>'integer','minimum'=>0],'sub_index'=>['type'=>'integer','default'=>0]]]),'responses'=>['200'=>$response('Verification result')]],
            ],
            '/api/auth/register' => [
                'post' => ['summary'=>'Register','requestBody'=>$json(['type'=>'object','required'=>['username','email','password'],'properties'=>['username'=>['type'=>'string','minLength'=>3,'maxLength'=>32],'email'=>['type'=>'string','format'=>'email'],'password'=>['type'=>'string','minLength'=>6,'maxLength'=>128]]]),'responses'=>['200'=>$response('Registered'),'409'=>$response('Username or email already exists')]],
            ],
            '/api/auth/login' => [
                'post' => ['summary'=>'Login','requestBody'=>$json(['type'=>'object','required'=>['username_or_email','password'],'properties'=>['username_or_email'=>['type'=>'string'],'password'=>['type'=>'string']]]),'responses'=>['200'=>$response('Logged in'),'401'=>$response('Invalid credentials'),'429'=>$response('Rate limited')]],
            ],
            '/api/auth/logout' => [
                'post' => ['summary'=>'Logout','security'=>[['sessionCookie'=>[]]],'responses'=>['200'=>$response('Logged out')]],
            ],
            '/api/auth/me' => [
                'get' => ['summary'=>'Current user','security'=>[['sessionCookie'=>[]]],'responses'=>['200'=>$response('Current user'),'401'=>$response('Not authenticated')]],
            ],
            '/api/auth/forgot-password' => [
                'post' => ['summary'=>'Reset password (demo flow)','requestBody'=>$json(['type'=>'object','required'=>['username','email','new_password'],'properties'=>['username'=>['type'=>'string','minLength'=>3,'maxLength'=>32],'email'=>['type'=>'string','format'=>'email'],'new_password'=>['type'=>'string','minLength'=>6,'maxLength'=>128]]]),'responses'=>['200'=>$response('Password reset')]],
            ],
            '/api/inventory' => [
                'get' => ['summary'=>'List inventory','security'=>[['sessionCookie'=>[]]],'responses'=>['200'=>$response('Inventory')]],
            ],
            '/api/inventory/{inventory_id}/sell' => [
                'post' => ['summary'=>'Sell one inventory item','security'=>[['sessionCookie'=>[]]],'parameters'=>[['name'=>'inventory_id','in'=>'path','required'=>true,'schema'=>['type'=>'integer']]],'responses'=>['200'=>$response('Sold')]],
            ],
            '/api/inventory/sell-all' => [
                'post' => ['summary'=>'Sell all inventory','security'=>[['sessionCookie'=>[]]],'responses'=>['200'=>$response('Sold')]],
            ],
            '/api/tradeup' => [
                'post' => ['summary'=>'Execute a 10-item trade-up','security'=>[['sessionCookie'=>[]]],'requestBody'=>$json(['type'=>'object','required'=>['inventory_ids'],'properties'=>['inventory_ids'=>['type'=>'array','minItems'=>10,'maxItems'=>10,'items'=>['type'=>'integer']]]]),'responses'=>['200'=>$response('Trade-up result')]],
            ],
            '/api/wallet/active-code' => [
                'get' => ['summary'=>'Get active hourly code','responses'=>['200'=>$response('Hourly code')]],
            ],
            '/api/wallet/redeem-code' => [
                'post' => ['summary'=>'Redeem hourly code','security'=>[['sessionCookie'=>[]]],'requestBody'=>$json(['type'=>'object','required'=>['code'],'properties'=>['code'=>['type'=>'string','minLength'=>6,'maxLength'=>6]]]),'responses'=>['200'=>$response('Redeemed')]],
            ],
            '/api/wallet/deposit' => [
                'post' => ['summary'=>'Demo deposit','security'=>[['sessionCookie'=>[]]],'requestBody'=>$json(['type'=>'object','required'=>['amount'],'properties'=>['amount'=>['type'=>'number','exclusiveMinimum'=>0,'maximum'=>10000]]]),'responses'=>['200'=>$response('Deposited')]],
            ],
            '/api/wallet/transactions' => [
                'get' => ['summary'=>'Wallet transactions','security'=>[['sessionCookie'=>[]]],'responses'=>['200'=>$response('Transactions')]],
            ],
            '/api/daily/status' => [
                'get' => ['summary'=>'Daily reward status','security'=>[['sessionCookie'=>[]]],'responses'=>['200'=>$response('Daily status')]],
            ],
            '/api/daily/claim' => [
                'post' => ['summary'=>'Claim daily reward','security'=>[['sessionCookie'=>[]]],'responses'=>['200'=>$response('Reward claimed'),'400'=>$response('Already claimed')]],
            ],
            '/api/admin/overview' => [
                'get' => ['summary'=>'Admin overview','security'=>[['sessionCookie'=>[]]],'responses'=>['200'=>$response('Overview'),'403'=>$response('Forbidden')]],
            ],
            '/api/admin/users' => [
                'get' => ['summary'=>'Admin users','security'=>[['sessionCookie'=>[]]],'responses'=>['200'=>$response('Users'),'403'=>$response('Forbidden')]],
            ],
            '/api/admin/users/{user_id}/balance' => [
                'post' => ['summary'=>'Adjust user balance','security'=>[['sessionCookie'=>[]]],'parameters'=>[['name'=>'user_id','in'=>'path','required'=>true,'schema'=>['type'=>'integer']]],'responses'=>['200'=>$response('Balance updated')]],
            ],
            '/api/admin/users/{user_id}/role' => [
                'post' => ['summary'=>'Update user role','security'=>[['sessionCookie'=>[]]],'parameters'=>[['name'=>'user_id','in'=>'path','required'=>true,'schema'=>['type'=>'integer']]],'responses'=>['200'=>$response('Role updated')]],
            ],
            '/api/admin/users/{user_id}/inventory' => [
                'get' => ['summary'=>'View user inventory','security'=>[['sessionCookie'=>[]]],'parameters'=>[['name'=>'user_id','in'=>'path','required'=>true,'schema'=>['type'=>'integer']]],'responses'=>['200'=>$response('Inventory')]],
            ],
            '/api/admin/cases' => [
                'get' => ['summary'=>'List cases for admin','security'=>[['sessionCookie'=>[]]],'responses'=>['200'=>$response('Cases')]],
            ],
            '/api/admin/cases/{case_id}' => [
                'post' => ['summary'=>'Update case','security'=>[['sessionCookie'=>[]]],'parameters'=>[['name'=>'case_id','in'=>'path','required'=>true,'schema'=>['type'=>'string']]],'responses'=>['200'=>$response('Updated')]],
            ],
            '/api/admin/giftcode' => [
                'get' => ['summary'=>'Giftcode manager','security'=>[['sessionCookie'=>[]]],'responses'=>['200'=>$response('Giftcode')]],
            ],
            '/api/admin/giftcode/generate' => [
                'post' => ['summary'=>'Generate hourly giftcode','security'=>[['sessionCookie'=>[]]],'responses'=>['200'=>$response('Generated')]],
            ],
            '/api/admin/giftcode/reward' => [
                'post' => ['summary'=>'Set giftcode reward','security'=>[['sessionCookie'=>[]]],'responses'=>['200'=>$response('Updated')]],
            ],
            '/api/admin/audit' => [
                'get' => ['summary'=>'Audit trail','security'=>[['sessionCookie'=>[]]],'responses'=>['200'=>$response('Audit logs')]],
            ],
            '/api/admin/logs' => [
                'get' => ['summary'=>'Application logs','security'=>[['sessionCookie'=>[]]],'responses'=>['200'=>$response('Logs')]],
            ],
            '/metrics' => [
                'get' => ['summary'=>'Prometheus metrics','responses'=>['200'=>['description'=>'Prometheus text format']]],
            ],
        ];

        return [
            'openapi' => '3.0.3',
            'info' => [
                'title' => 'CS2 Case Opening Simulator Platform',
                'version' => '2.0.0-php',
                'description' => 'PHP/MySQL/Apache port of the original FastAPI CS2 Case Simulator.',
            ],
            'servers' => [['url' => '/']],
            'tags' => [
                ['name'=>'Auth'],['name'=>'Cases'],['name'=>'Inventory'],['name'=>'TradeUp'],
                ['name'=>'Wallet'],['name'=>'Daily'],['name'=>'Admin'],['name'=>'Monitoring'],
            ],
            'components' => [
                'securitySchemes' => [
                    'sessionCookie' => ['type'=>'apiKey','in'=>'cookie','name'=>'cs2_session'],
                ],
            ],
            'paths' => $paths,
        ];
    }

    public static function docsHtml(string $kind): string
    {
        $specUrl = '/api/openapi.json';

        if ($kind === 'redoc') {
            return '<!doctype html><html><head><meta charset="utf-8"><title>CS2 API ReDoc</title></head><body><redoc spec-url="' . $specUrl . '"></redoc><script src="https://cdn.jsdelivr.net/npm/redoc@latest/bundles/redoc.standalone.js"></script></body></html>';
        }

        return '<!doctype html><html><head><meta charset="utf-8"><title>CS2 API Swagger UI</title><link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/swagger-ui-dist@latest/swagger-ui.css"></head><body><div id="swagger-ui"></div><script src="https://cdn.jsdelivr.net/npm/swagger-ui-dist@latest/swagger-ui-bundle.js"></script><script>window.onload=function(){window.ui=SwaggerUIBundle({url:"' . $specUrl . '",dom_id:"#swagger-ui"});};</script></body></html>';
    }
}
