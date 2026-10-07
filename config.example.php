<?php
/**
 * Modelo de configuração. Copie para config.php e preencha.
 * NUNCA versionar config.php em git.
 */
return [
    'app' => [
        'name'       => 'Asaas → Notazz',
        'env'        => 'production',       // production | development
        'base_url'   => 'https://notas.example.com',
        'timezone'   => 'America/Recife',
        'debug'      => false,
    ],

    'db' => [
        'host'       => 'localhost',
        'port'       => 3306,
        'database'   => 'INFORME_AQUI',
        'username'   => 'INFORME_AQUI',
        'password'   => 'INFORME_AQUI',
        'charset'    => 'utf8mb4',
    ],

    'asaas' => [
        // Token da API Asaas (usado para CHAMAR a API, ex: buscar customer)
        'api_token'      => 'COLE_AQUI_O_TOKEN_DA_API_ASAAS',
        'api_base'       => 'https://api.asaas.com/v3',

        // Token de validação do WEBHOOK (você define no painel Asaas) — conta Educação
        'webhook_token'  => 'COLE_AQUI_O_TOKEN_SECRETO_DO_WEBHOOK',

        // Outras contas Asaas (multi-empresa): tokens de webhook adicionais aceitos.
        // Ex.: o token do webhook da conta Asaas da Empresa 2 (Mentorias).
        'webhook_tokens' => [
            // 'whsec_xxxxxxxxxxxxxxxxxxxxxxxx', // Mentorias
        ],

        // Eventos aceitos para emissão (filtro inicial).
        'trigger_events' => ['PAYMENT_CONFIRMED', 'PAYMENT_RECEIVED'],

        // Para CADA forma de pagamento, qual evento dispara emissão.
        // Evita duplicação no cartão (CONFIRMED+RECEIVED) e funciona com PIX.
        'trigger_event_by_billing_type' => [
            'CREDIT_CARD' => 'PAYMENT_CONFIRMED',
            'BOLETO'      => 'PAYMENT_RECEIVED',
            'PIX'         => 'PAYMENT_RECEIVED',
            'DEBIT_CARD'  => 'PAYMENT_RECEIVED',
            'TRANSFER'    => 'PAYMENT_RECEIVED',
            'DEPOSIT'     => 'PAYMENT_RECEIVED',
            'UNDEFINED'   => 'PAYMENT_RECEIVED',
            '_default'    => 'PAYMENT_RECEIVED',
        ],
    ],

    'notazz' => [
        'api_key'        => 'COLE_AQUI_A_API_KEY_DA_NOTAZZ',
        'api_base'       => 'https://app.notazz.com/api',
        'timeout'        => 30,
    ],

    'emitter' => [
        'cnpj'           => '11111111000111',
        'name'           => 'EMPRESA EXEMPLO EDUCACAO LTDA',
        'state'          => 'RN',
        'city_ibge'      => '2611606',
        // Fallback usado quando o cliente do Asaas não tem endereço completo
        'default_address' => [
            'street'        => 'nao informado',
            'number'        => 'SN',
            'complement'    => '',
            'district'      => 'nao informado',
            'city'          => 'Recife',
            'state'         => 'RN',
            'zipcode'       => '50000000',
        ],
        'split' => [
            'nfe_percent' => 50,
        ],
        // Dias de atraso antes da Notazz enviar a nota pra SEFAZ/Prefeitura
        // (tempo para o financeiro revisar). 0 = envio imediato.
        'auto_emission_delay_days' => 8,
    ],

    'queue' => [
        'batch_size'     => 10,
        'max_attempts'   => 5,
        'retry_backoff'  => [60, 300, 900, 3600, 21600], // segundos por tentativa
    ],

    // Login PRÓPRIO da aba de Inadimplentes (HTTP Basic Auth, separado do painel).
    // Use 'pass' (senha em texto) OU 'pass_hash' (bcrypt). Se os dois existirem, o hash tem prioridade.
    // Hash opcional: php -r "echo password_hash('SENHA', PASSWORD_BCRYPT);"
    // Para VÁRIOS acessos, use o mapa 'users' (usuário => senha em texto ou hash bcrypt).
    'inadimplentes' => [
        'user' => 'financeiro',
        'pass' => 'DEFINA_A_SENHA_AQUI',
        'users' => [
            'financeiro' => 'DEFINA_A_SENHA_AQUI',
            'gestor'     => 'DEFINA_A_SENHA_AQUI',
            'suporte'    => 'DEFINA_A_SENHA_AQUI',
        ],
    ],

    'security' => [
        // Hash bcrypt da senha do admin inicial - NÃO use texto puro
        // Gere com: php -r "echo password_hash('SUA_SENHA', PASSWORD_BCRYPT);"
        'session_name'   => 'NOTASADM',
        'session_lifetime' => 14400,        // 4 horas
        'csrf_secret'    => 'GERE_UM_HEX_ALEATORIO_AQUI',
    ],

    'paths' => [
        'storage' => __DIR__ . '/storage',
        'logs'    => __DIR__ . '/storage/logs',
        'pdf'     => __DIR__ . '/storage/pdf',
        'xml'     => __DIR__ . '/storage/xml',
    ],
];
