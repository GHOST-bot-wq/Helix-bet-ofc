<?php
// ===================================================================
//  includes/facebook_pixel.php
//  Facebook Pixel + Conversions API (server-side)
//  Compatível com PHP 7.2+
//
//  Eventos suportados:
//    PageView, ViewContent, InitiateCheckout,
//    CompleteRegistration, Purchase
// ===================================================================

if (!function_exists('fb_pixel_ativo')) :

/**
 * Retorna true se o Pixel estiver ativo e configurado.
 */
function fb_pixel_ativo()
{
    return cfg('facebook_pixel_ativo', '0') === '1'
        && cfg('facebook_pixel_id', '') !== '';
}

/**
 * Gera um event_id único (para deduplicação browser ↔ CAPI).
 */
function fb_event_id($evento, $extra = '')
{
    return $evento . '_' . substr(md5(uniqid($extra, true)), 0, 16);
}

/**
 * Retorna o IP real do visitante (respeita proxies).
 */
function fb_get_ip()
{
    foreach (['HTTP_CF_CONNECTING_IP', 'HTTP_X_FORWARDED_FOR', 'REMOTE_ADDR'] as $k) {
        if (!empty($_SERVER[$k])) {
            $ip = trim(explode(',', $_SERVER[$k])[0]);
            if (filter_var($ip, FILTER_VALIDATE_IP)) return $ip;
        }
    }
    return null;
}

/**
 * Hash SHA-256 lowercase (padrão Meta CAPI).
 */
function fb_hash($valor)
{
    if ($valor === null || $valor === '') return null;
    return hash('sha256', strtolower(trim($valor)));
}

/**
 * Envia um evento para a Conversions API (server-side).
 *
 * @param string $event_name  Nome do evento Meta (ex: "Purchase")
 * @param array  $params      Dados do evento (value, currency, etc.)
 * @param array  $user_data   Dados do usuário hasheados
 * @param string $event_id    ID único para deduplicação
 */
function fb_capi_send($event_name, $params = [], $user_data = [], $event_id = '')
{
    if (!fb_pixel_ativo()) return;

    $access_token   = cfg('facebook_access_token', '');
    $pixel_id       = cfg('facebook_pixel_id', '');
    $test_event_code= cfg('facebook_test_event_code', '');

    if (!$access_token || !$pixel_id) return;

    // Dados do usuário
    $ud = [];

    // IP e User-Agent sempre coletados
    $ip = fb_get_ip();
    if ($ip) $ud['client_ip_address'] = $ip;

    $ua = isset($_SERVER['HTTP_USER_AGENT']) ? $_SERVER['HTTP_USER_AGENT'] : null;
    if ($ua) $ud['client_user_agent'] = $ua;

    // Dados hasheados passados pelo caller
    $hash_fields = ['em', 'ph', 'fn', 'ln', 'ct', 'st', 'zp', 'country', 'external_id'];
    foreach ($hash_fields as $f) {
        if (!empty($user_data[$f])) {
            $ud[$f] = is_array($user_data[$f]) ? $user_data[$f] : [$user_data[$f]];
        }
    }

    // fbp / fbc dos cookies do browser
    if (!empty($_COOKIE['_fbp'])) $ud['fbp'] = $_COOKIE['_fbp'];
    if (!empty($_COOKIE['_fbc'])) $ud['fbc'] = $_COOKIE['_fbc'];

    $event = [
        'event_name'       => $event_name,
        'event_time'       => time(),
        'event_id'         => $event_id ?: fb_event_id($event_name),
        'event_source_url' => ((!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http')
                              . '://' . ($_SERVER['HTTP_HOST'] ?? 'localhost')
                              . ($_SERVER['REQUEST_URI'] ?? '/'),
        'action_source'    => 'website',
        'user_data'        => $ud,
    ];

    if (!empty($params)) {
        $event['custom_data'] = $params;
    }

    $payload = ['data' => [$event]];
    if ($test_event_code) {
        $payload['test_event_code'] = $test_event_code;
    }

    $url = 'https://graph.facebook.com/v19.0/' . $pixel_id
         . '/events?access_token=' . urlencode($access_token);

    // cURL assíncrono (fire-and-forget com timeout mínimo)
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => json_encode($payload),
        CURLOPT_HTTPHEADER     => ['Content-Type: application/json'],
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 5,
        CURLOPT_CONNECTTIMEOUT => 3,
        CURLOPT_SSL_VERIFYPEER => true,
    ]);
    $resp = curl_exec($ch);
    $err  = curl_error($ch);
    curl_close($ch);

    // Log silencioso em caso de erro
    if ($err) {
        fb_pixel_log('CAPI_CURL_ERRO', $event_name . ': ' . $err);
    }

    return $resp;
}

/**
 * Retorna o snippet HTML do Pixel para ser injetado no <head>.
 * Inclui o event_id para deduplicação.
 *
 * @param string $event_name  Ex: "PageView"
 * @param array  $event_data  Dados adicionais do evento JS
 * @param string $event_id    Mesmo event_id enviado via CAPI
 */
function fb_pixel_html($event_name = 'PageView', $event_data = [], $event_id = '')
{
    if (!fb_pixel_ativo()) return '';

    $pixel_id = cfg('facebook_pixel_id', '');
    if (!$pixel_id) return '';

    $eid   = $event_id ?: fb_event_id($event_name);
    $edata = empty($event_data) ? '{}' : json_encode($event_data, JSON_UNESCAPED_UNICODE);

    ob_start();
    ?>
<!-- Facebook Pixel Code -->
<script>
!function(f,b,e,v,n,t,s){if(f.fbq)return;n=f.fbq=function(){n.callMethod?
n.callMethod.apply(n,arguments):n.queue.push(arguments)};if(!f._fbq)f._fbq=n;
n.push=n;n.loaded=!0;n.version='2.0';n.queue=[];t=b.createElement(e);t.async=!0;
t.src=v;s=b.getElementsByTagName(e)[0];s.parentNode.insertBefore(t,s)}(window,
document,'script','https://connect.facebook.net/en_US/fbevents.js');
fbq('init', '<?php echo htmlspecialchars($pixel_id); ?>');
fbq('track', '<?php echo htmlspecialchars($event_name); ?>', <?php echo $edata; ?>, {eventID: '<?php echo $eid; ?>'});
</script>
<noscript><img height="1" width="1" style="display:none"
  src="https://www.facebook.com/tr?id=<?php echo htmlspecialchars($pixel_id); ?>&ev=PageView&noscript=1"
/></noscript>
<!-- End Facebook Pixel Code -->
    <?php
    return ob_get_clean();
}

/**
 * Dispara um único evento JS adicional (após o pixel já ter sido inicializado).
 */
function fb_pixel_track_html($event_name, $event_data = [], $event_id = '')
{
    if (!fb_pixel_ativo()) return '';
    $eid   = $event_id ?: fb_event_id($event_name);
    $edata = empty($event_data) ? '{}' : json_encode($event_data, JSON_UNESCAPED_UNICODE);
    return "<script>if(typeof fbq==='function'){fbq('track','"
        . htmlspecialchars($event_name) . "',"
        . $edata . ",{eventID:'" . $eid . "'});}</script>\n";
}

// ===================================================================
//  Funções de conveniência para cada evento
// ===================================================================

/**
 * PageView — chamado em cada página carregada.
 */
function fb_event_pageview($user_data = [])
{
    $eid = fb_event_id('PageView');
    fb_capi_send('PageView', [], $user_data, $eid);
    return ['event_id' => $eid, 'html' => fb_pixel_html('PageView', [], $eid)];
}

/**
 * ViewContent — usuário visualiza conteúdo relevante (ex: página de depósito).
 *
 * @param string $content_name  Ex: "Deposito", "Jogo"
 * @param array  $user_data
 */
function fb_event_view_content($content_name = '', $user_data = [])
{
    $eid    = fb_event_id('ViewContent');
    $params = ['content_name' => $content_name, 'content_type' => 'product'];
    fb_capi_send('ViewContent', $params, $user_data, $eid);
    return ['event_id' => $eid, 'html' => fb_pixel_track_html('ViewContent', $params, $eid)];
}

/**
 * InitiateCheckout — usuário iniciou o processo de depósito.
 *
 * @param float  $valor
 * @param array  $user_data
 */
function fb_event_initiate_checkout($valor = 0.0, $user_data = [])
{
    $eid    = fb_event_id('InitiateCheckout');
    $params = [
        'value'    => round((float)$valor, 2),
        'currency' => 'BRL',
    ];
    fb_capi_send('InitiateCheckout', $params, $user_data, $eid);
    return ['event_id' => $eid, 'html' => fb_pixel_track_html('InitiateCheckout', $params, $eid)];
}

/**
 * CompleteRegistration — novo usuário cadastrado.
 *
 * @param array $user_data  email/phone hasheados
 */
function fb_event_complete_registration($user_data = [])
{
    $eid    = fb_event_id('CompleteRegistration');
    $params = ['status' => 'completed'];
    fb_capi_send('CompleteRegistration', $params, $user_data, $eid);
    return ['event_id' => $eid, 'html' => fb_pixel_track_html('CompleteRegistration', $params, $eid)];
}

/**
 * Purchase — depósito confirmado (webhook).
 * Chamado APENAS server-side via Conversions API.
 *
 * @param float  $valor
 * @param int    $usuario_id
 * @param string $order_id    ID da transação (para deduplicação)
 * @param array  $user_data   Dados do usuário (email/phone já hasheados)
 */
function fb_event_purchase($valor, $usuario_id = 0, $order_id = '', $user_data = [])
{
    $eid    = fb_event_id('Purchase', $order_id);
    $params = [
        'value'          => round((float)$valor, 2),
        'currency'       => 'BRL',
        'content_type'   => 'product',
        'content_ids'    => ['deposito'],
        'order_id'       => $order_id,
    ];

    // Se user_data não veio populado, busca no banco
    if (empty($user_data) && $usuario_id > 0) {
        $user_data = fb_get_user_data($usuario_id);
    }

    fb_capi_send('Purchase', $params, $user_data, $eid);
    fb_pixel_log('CAPI_PURCHASE_ENVIADO',
        'usuario=' . $usuario_id . ' valor=' . $valor . ' order=' . $order_id . ' eid=' . $eid);

    return $eid;
}

/**
 * Busca dados do usuário no banco e retorna hasheados para a CAPI.
 */
function fb_get_user_data($usuario_id)
{
    try {
        $stmt = db()->prepare('SELECT email, telefone FROM usuarios WHERE id = ? LIMIT 1');
        $stmt->execute([$usuario_id]);
        $u = $stmt->fetch();
        if (!$u) return [];

        $ud = [];
        if (!empty($u['email']))    $ud['em'] = [fb_hash($u['email'])];
        if (!empty($u['telefone'])) {
            // Normaliza telefone: apenas dígitos, adiciona DDI 55 se precisar
            $tel = preg_replace('/\D/', '', $u['telefone']);
            if (strlen($tel) <= 11) $tel = '55' . $tel;
            $ud['ph'] = [fb_hash($tel)];
        }
        return $ud;
    } catch (Exception $e) {
        return [];
    }
}

/**
 * Logger interno do módulo Pixel.
 */
function fb_pixel_log($acao, $detalhes)
{
    try {
        $ip = isset($_SERVER['REMOTE_ADDR']) ? $_SERVER['REMOTE_ADDR'] : null;
        db()->prepare(
            'INSERT INTO admin_logs (admin_id, acao, detalhes, ip) VALUES (NULL, ?, ?, ?)'
        )->execute([$acao, substr((string)$detalhes, 0, 1000), $ip]);
    } catch (Exception $e) {}
}

endif; // function_exists guard
