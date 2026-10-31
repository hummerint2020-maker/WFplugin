<?php
/**
 * The golden harness's way to run an admin-post handler in process (tests/golden/actions_org.php; docs/tasks/people-org-writes.md §4).
 * A handler ends in wp_redirect() + exit or wp_die(): both are turned into an exception that carries where it went, so the
 * trace can record it and the script goes on. Include it once (require_once) before calling golden_post().
 */
if (!class_exists('GoldenExit')) {
    class GoldenExit extends Exception
    {
        /** @var string redirect | die */
        public $kind;
        /** @var string the location, or the message */
        public $to;
        public function __construct($kind, $to)
        {
            parent::__construct($kind . ' ' . $to);
            $this->kind = $kind;
            $this->to = $to;
        }
    }
    add_filter('wp_redirect', function ($to) { throw new GoldenExit('redirect', (string) $to); }, PHP_INT_MAX);
    add_filter('wp_die_handler', function () {
        return function ($message, $title = '', $args = []) {
            throw new GoldenExit('die', is_string($message) ? $message : (is_wp_error($message) ? $message->get_error_message() : ''));
        };
    }, PHP_INT_MAX);
}

/**
 * Calls a public admin-post method as the current user with a real nonce. $post: the form fields. $nonceAction: the action of the nonce
 * (null = no nonce at all, 'bad' = a wrong one). $referer: the HTTP referer some handlers read.
 * @return array{exit:string,to:string}
 */
function golden_post($object, $method, array $post, $nonceAction = null, $referer = null)
{
    $_GET = [];
    $fields = $post;
    if ($nonceAction === 'bad') $fields['_wpnonce'] = 'badnonce000';
    elseif ($nonceAction !== null) $fields['_wpnonce'] = wp_create_nonce($nonceAction);
    $_POST = wp_slash($fields);
    $_REQUEST = $_POST;
    $_SERVER['REQUEST_METHOD'] = 'POST';
    $_SERVER['REQUEST_URI'] = '/wp-admin/admin-post.php';
    if ($referer === null) unset($_SERVER['HTTP_REFERER']); else $_SERVER['HTTP_REFERER'] = $referer;
    $_REQUEST['_wp_http_referer'] = $referer ?? '';
    try {
        $object->$method();
        return ['exit' => 'none', 'to' => ''];
    } catch (GoldenExit $e) {
        return ['exit' => $e->kind, 'to' => $e->to];
    } finally {
        $_POST = $_REQUEST = $_GET = [];
    }
}

/**
 * Like golden_post() for a handler that reads $_GET (a link with a nonce): the same exit handling.
 * @return array{exit:string,to:string}
 */
function golden_get($object, $method, array $get, $nonceAction = null)
{
    $_POST = [];
    $fields = $get;
    if ($nonceAction === 'bad') $fields['_wpnonce'] = 'badnonce000';
    elseif ($nonceAction !== null) $fields['_wpnonce'] = wp_create_nonce($nonceAction);
    $_GET = wp_slash($fields);
    $_REQUEST = $_GET;
    $_SERVER['REQUEST_METHOD'] = 'GET';
    $_SERVER['REQUEST_URI'] = '/wp-admin/admin-post.php';
    unset($_SERVER['HTTP_REFERER']);
    try {
        $object->$method();
        return ['exit' => 'none', 'to' => ''];
    } catch (GoldenExit $e) {
        return ['exit' => $e->kind, 'to' => $e->to];
    } finally {
        $_POST = $_REQUEST = $_GET = [];
    }
}

if (!function_exists('golden_json')) {
    add_filter('wp_die_ajax_handler', function () {
        return function ($message, $title = '', $args = []) {
            throw new GoldenExit('die', is_string($message) ? $message : (string) (is_wp_error($message) ? $message->get_error_message() : ''));
        };
    }, PHP_INT_MAX);

    /**
     * Runs an AJAX handler (wp_send_json_*) in process as the current user: wp_doing_ajax() is true while it runs, the answer it prints is
     * caught, and the exit that follows it is turned into an exception.
     * @return array{exit:string,to:string,json:mixed}
     */
    function golden_json($object, $method, array $post, $nonceAction = null)
    {
        $force = function () { return true; };
        add_filter('wp_doing_ajax', $force, PHP_INT_MAX);
        $fields = $post;
        if ($nonceAction === 'bad') $fields['_wpnonce'] = 'badnonce000';
        elseif ($nonceAction !== null) $fields['_wpnonce'] = wp_create_nonce($nonceAction);
        $_GET = [];
        $_POST = wp_slash($fields);
        $_REQUEST = $_POST;
        $_SERVER['REQUEST_METHOD'] = 'POST';
        $_SERVER['REQUEST_URI'] = '/wp-admin/admin-ajax.php';
        ob_start();
        $exit = 'none';
        $to = '';
        try {
            $object->$method();
        } catch (GoldenExit $e) {
            $exit = $e->kind;
            $to = $e->to;
        } finally {
            $printed = (string) ob_get_clean();
            remove_filter('wp_doing_ajax', $force, PHP_INT_MAX);
            $_POST = $_REQUEST = $_GET = [];
        }
        return ['exit' => $exit, 'to' => $to, 'json' => json_decode($printed, true)];
    }

    /**
     * Runs a wp-admin page method as the current user with a query string and returns what it printed (nonces and times are the caller's to normalise).
     * @return array{exit:string,to:string,html:string}
     */
    function golden_page($object, $method, array $get)
    {
        $_POST = [];
        $_GET = wp_slash($get);
        $_REQUEST = $_GET;
        $_SERVER['REQUEST_METHOD'] = 'GET';
        $_SERVER['REQUEST_URI'] = '/wp-admin/admin.php';
        ob_start();
        $exit = 'none';
        $to = '';
        try {
            $object->$method();
        } catch (GoldenExit $e) {
            $exit = $e->kind;
            $to = $e->to;
        } finally {
            $html = (string) ob_get_clean();
            $_POST = $_REQUEST = $_GET = [];
        }
        return ['exit' => $exit, 'to' => $to, 'html' => $html];
    }
}
