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
