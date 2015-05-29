<?php
/*
Plugin Name: WP Advanced Search Framework
Plugin URI: http://wpadvancedsearch.com
Description: 
Version: 1.4
Author: Sean Butze
Author URI: http://seanbutze.com
License: GPLv2 or later
*/

require_once('config/form.default.php');

$WPAS_FORMS = array();

/**
 * Class Autoloader
 *
 * Adapted from PHP-FIG:
 * http://www.php-fig.org/psr/psr-4/examples/
 *
 */
spl_autoload_register(function ($class) {

    // project-specific namespace prefix
    $prefix = 'WPAS\\';

    // base directory for the namespace prefix
    $base_dir = __DIR__ . '/src/';

    // does the class use the namespace prefix?
    $len = strlen($prefix);
    if (strncmp($prefix, $class, $len) !== 0) {
        // no, move to the next registered autoloader
        return;
    }

    // get the relative class name
    $relative_class = substr($class, $len);

    // replace the namespace prefix with the base directory, replace namespace
    // separators with directory separators in the relative class name, append
    // with .php
    $file = $base_dir . str_replace('\\', '/', $relative_class) . '.php';

    // if the file exists, require it
    if (file_exists($file)) {
        require $file;
    }
});

// Ajax Stuff
function wpas_scripts() {
    wp_enqueue_script( 'ajax-scripts', get_template_directory_uri() . '/' . basename(__DIR__) . '/js/ajax.js', array(), '1', false );
    wp_enqueue_script( 'admin-ajax', admin_url( 'admin-ajax.php' ), array(), '1', false );
    wp_localize_script( 'admin-ajax', 'WPAS_Ajax', array( 'ajaxurl' => admin_url( 'admin-ajax.php' ) ) );
}
add_action('wp_enqueue_scripts', 'wpas_scripts');

function load_template_part($template, $query_object) {
    global $wp_query;
    $temp = $wp_query;
    $wp_query = $query_object;
    ob_start();
    load_template(__DIR__.'/demo/'.$template);
    $var = ob_get_contents();
    ob_end_clean();

    $wp_query = $temp;
    return $var;
}

function wpas_ajax_load() {

        $request = array();

        if (isset($_POST['form_data'])) {
            parse_str($_POST['form_data'], $request);
        }

        $wpas_id = $request['wpas_id'];
        $wpas = new WP_Advanced_Search($wpas_id, $request);
        $q = $wpas->query();
        $template_name = 'template-results.php';
        //$pagination = $wpas->get_pagination($q, '', true);

        $response = array();
        $response["results"] = load_template_part($template_name,$q);
        //$response["pagination"] = $pagination;
        $response["current_page"] = $q->query_vars['paged'];
        $response["max_page"] = $q->max_num_pages;

        $response["debug"] = "";

        if ($wpas->debug_enabled()) $response["debug"] = "<pre>". $wpas->create_debug_output() . "</pre>";

        echo json_encode($response);
        wp_die();
}

// creating Ajax call for WordPress
add_action( 'wp_ajax_nopriv_wpas_ajax_load', 'wpas_ajax_load' );
add_action( 'wp_ajax_wpas_ajax_load', 'wpas_ajax_load' );
//

function register_wpas_form($name, $args) {
    global $WPAS_FORMS;
    if (!is_array($args)) return;
    $args["wpas_id"] = $name;
    $WPAS_FORMS[$name] = $args;
}

class WP_Advanced_Search {
    private $factory;
    private $errors;
    private $args;
    private $request;
    private $debug;
    private $ajax;
    public $debug_level;

    function __construct($id = '', $request = false) {
        $this->errors = array();
        $this->args = $this->get_form_args($id);
        $this->args = $this->process_args($this->args);
        $this->ajax = $this->args['form']['ajax'];
        $this->debug = $this->args['debug'];
        $this->debug_level = $this->args['debug_level'];
        $this->request = ($request) ? $request : $_REQUEST;
        $this->factory = new WPAS\Factory($this->args, $this->request);
    }

    /**
     * Get arguments for a form based on its registered ID
     *
     * @param $id
     * @return array|mixed
     */
    public function get_form_args($id) {
        global $WPAS_FORMS;

        if (empty($WPAS_FORMS)) {
            $this->errors[] = "No forms have been configured.";
            return array();
        }
        if (empty($id)) {
            if (!empty($WPAS_FORMS['default'])) return $WPAS_FORMS['default'];
            else return reset($WPAS_FORMS);
        } else if (empty($WPAS_FORMS[$id])) {
            $this->errors[] = "WPAS form with ID \"".$id."\" is not registered.";
            return array();
        }
        return $WPAS_FORMS[$id];
    }

    /**
     * Print HTML content of the search form
     */
    public function the_form() {
        $form = $this->factory->getForm();
        if ($this->debug) $form->addClass('debug-enabled');
        echo $form->toHTML();
    }

    /**
     * Create and return WP_Query object for the search instance
     *
     * @return WP_Query
     */
    public function query() {
        $query = $this->factory->buildQueryObject();
        if (!$this->ajax_enabled()) $this->print_debug();
        return $query;
    }

    /**
     * Displays range of results displayed on the current page.
     *
     * @return string
     */
    function results_range( $args = array() ) {
        global $wp_query;

        $defaults = array(
            'pre' => '',
            'marker' => '-',
            'post' => ''
        );

        $args = wp_parse_args($args, $defaults);
        extract($args);

        $total = $wp_query->found_posts;
        $count = $wp_query->post_count;
        $query = $wp_query->query;
        $ppp = (!empty($query['posts_per_page'])) ? $query['posts_per_page'] : get_option('posts_per_page');
        $page = get_query_var('paged');

        $range = $page;
        if ($ppp > 1) {
            $i = 1 + (($page - 1)*$ppp);
            $j = $i + ($ppp - 1);
            $range = sprintf('%d%s%d', $i, $marker, $j);
            if ($j > $total) {
                $range = $total;
            }
        }

        if ($count < 1) {
            $range = 0;
        }

        $output = sprintf('<span>%s</span> <span>%s</span> <span>%s</span>', $pre, $range, $post);

        return $output;
    }

    /**
     * Displays pagination links
     */
    public function pagination( $args = '', $ajax = false) {
        global $wp_query;
        echo $this->get_pagination($wp_query, $args, $ajax);
    }

    /**
     * Get HTML for pagination links
     *
     * @param $query_object
     * @param string $args
     * @return string
     */
    public function get_pagination($query_object, $args = '', $ajax = false) {
        global $wp_query;
        $temp = $wp_query;
        $wp_query = $query_object;

        $output = "";

        $current_page = max(1, get_query_var('paged'));
        $total_pages = $wp_query->max_num_pages;

        $b = '999999999';

        if ($this->ajax_enabled()) {
            $base = "#";
        } else {
            $base = str_replace( $b, '%#%', esc_url( get_pagenum_link( $b ) ) );
        }

        $defaults = array(
            'base' => $base,
            'format' => 'page/%#%',
            'current' => $current_page,
            'total' => $total_pages
        );

        if (empty($args) && !empty($this->args['pagination'])) {
            $args = $this->args['pagination'];
        }

        $args = wp_parse_args($args, $defaults);

        if ($total_pages > 1){
            $output .=  '<div class="pagination">';
            $output .= paginate_links($args);
            $output .=  '</div>';
        }

        $wp_query = $temp;

        return $output;
    }

    /**
     * Get full WP Query object
     *
     * @return object
     */
    public function get_wp_query_object() {
        return $this->factory->getWPQuery();
    }

    /**
     * Create string of debug information
     *
     * For use when WPAS_DEBUG is enabled, or when calling the
     * print_debug() method.
     *
     * When $log is set to 'verbose', the output will contain a full var dump
     * of the generated WP_Query object.
     *
     * @param string $level
     * @return string
     */
    public function create_debug_output() {
        $level = $this->debug_level;
        $errors = $this->get_errors();
        $wp_query_obj = $this->factory->getWPQuery();


        $output = "WPAS DEBUG\n";

        $output .= "------------------------------------\n";
        $output .= "|| Errors\n";
        $output .= "------------------------------------\n";

        if (empty($errors)) {
            $output .= "No errors detected.\n";
        } else {
            $output .= count($errors) . " errors detected:\n";
            $output .= print_r($errors, true) . "\n";
        }

        $output .= "------------------------------------\n";
        $output .= "|| WP_Query Arguments\n";
        $output .= "------------------------------------\n";

        $output .= print_r($wp_query_obj->query, true) . "\n";

        $output .= "------------------------------------\n";
        $output .= "|| MySQL Query \n";
        $output .= "------------------------------------\n";

        $output .= print_r($wp_query_obj->request, true) . "\n";

        $output .= "------------------------------------\n";
        $output .= "|| Request Data \n";
        $output .= "------------------------------------\n";

        $output .= print_r($this->request, true) . "\n";

        if ($level == 'verbose') {
            $output .= "------------------------------------\n";
            $output .= "|| WP_Query Object Dump\n";
            $output .= "------------------------------------\n";
            $output .= print_r($wp_query_obj, true);
        }

        return $output;
    }

    /**
     * Print debug information
     */
    public function print_debug() {
        if ($this->debug == false) return;
        $output = $this->create_debug_output();
        echo '<pre>' . $output . '</pre>';
    }

    /**
     * @return bool
     */
    public function debug_enabled() {
       return $this->debug;
    }

    /**
     * Get array of errors generated during setup/configuration of search
     * instance
     *
     * @return array
     */
    public function get_errors() {
        $errors = $this->errors;
        if (is_object($this->factory)) {
            $errors = array_merge($this->errors, $this->factory->getErrors());
        }
        return $errors;
    }

    /**
     * Get Ajax configuration
     *
     * @return mixed
     */
    public function get_ajax() {
        return $this->ajax;
    }

    /**
     * Returns true if ajax is enabled for the current search instance
     *
     * @return bool
     */
    public function ajax_enabled() {
        return $this->ajax->isEnabled();
    }

    /**
     * Pre process arguments, translate argument blocks into config objects
     *
     * @param $args
     * @return mixed
     */
    private function process_args($args) {

        // Establish AJAX configuration
        $ajax_args = array();
        if (!isset($args['form'])) $args['form'] = array();

        if (isset($args['form']['ajax'])) {
            $ajax_args = $args['form']['ajax'];
        }
        $args['form']['ajax'] = new WPAS\AjaxConfig($ajax_args);

        // Set debug mode and debug level
        $debug = false;
        if (defined('WPAS_DEBUG') && WPAS_DEBUG) {
            $debug = true;
        } else if (!empty($args['debug']) && $args['debug']) {
            $debug = true;
        }

        $debug_level = 'log';

        if (defined('WPAS_DEBUG_LEVEL') && WPAS_DEBUG_LEVEL) {
            $debug_level = WPAS_DEBUG_LEVEL;
        } else if (!empty($args['debug_level']) && $args['debug_level']) {
            $debug_level = $args['debug_level'];
        }

        $args['debug'] = $debug;
        $args['debug_level'] = $debug_level;

        return $args;
    }

}
