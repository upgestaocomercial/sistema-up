<?php
// Copie para config/config.php e preencha. O config.php NÃO vai para o GitHub.
return [
    'db' => [
        'host' => 'localhost',
        'name' => 'usuario_cpanel_upsistema',
        'user' => 'usuario_cpanel_up',
        'pass' => '',
        'charset' => 'utf8mb4',
    ],
    'app' => [
        'url' => 'https://sistema.upgestaocomercial.com.br',
        'timezone' => 'America/Sao_Paulo',
        'debug' => false,
        // caminho absoluto da pasta storage (fora do public_html)
        'storage' => __DIR__ . '/../storage',
        // cookie de sessão só em HTTPS em produção
        'cookie_secure' => true,
    ],
    'sessao' => [
        'horas_navegador' => 12,
        'dias_app' => 30,       // celular: fica logado 30 dias
        'dias_tv' => 365,       // aparelho da TV
    ],
    'smtp' => [
        'host' => '',
        'port' => 465,
        'user' => '',
        'pass' => '',
        'from' => 'sistema@upgestaocomercial.com.br',
        'from_name' => 'UP Gestão Comercial',
    ],
];
