<?php

if (!defined('ABSPATH')) {
    exit; // Exit if accessed directly.
}

class EP_WP_Query_Integration
{

    /**
     * Is set only when we are within a multisite loop
     *
     * @var bool|WP_Query
     */
    private $query_stack = array();

    private $posts_by_query = array();

    /**
     * Placeholder method
     *
     * @since 0.9
     */
    public function __construct()
    {
    }

    /**
     * Return a singleton instance of the current class
     *
     * @since 0.9
     * @return object
     */
    public static function factory()
    {
        static $instance = false;

        if (!$instance) {
            $instance = new self();
            add_action('init', array($instance, 'setup'));
        }

        return $instance;
    }

    /**
     * Checks to see if we should be integrating and if so, sets up the appropriate actions and filters.
     * @since 0.9
     */
    public function setup()
    {

        /**
         * By default EP will not integrate on admin or ajax requests. Since admin-ajax.php is
         * technically an admin request, there is some weird logic here. If we are doing ajax
         * and ep_ajax_wp_query_integration is filtered true, then we skip the next admin check.
         */
        $admin_integration = apply_filters('ep_admin_wp_query_integration', false);

        if (defined('DOING_AJAX') && DOING_AJAX) {
            if (!apply_filters('ep_ajax_wp_query_integration', false)) {
                return;
            } else {
                $admin_integration = true;
            }
        }

        if (is_admin() && !$admin_integration) {
            return;
        }

        // Ensure that we are currently allowing ElasticPress to override the normal WP_Query search
        if (!ep_is_activated()) {
            return;
        }

        // Make sure we return nothing for MySQL posts query
        add_filter('posts_request', array($this, 'filter_posts_request'), 10, 2);

        // Add header
        add_action('pre_get_posts', array($this, 'action_pre_get_posts'), 5);

        // Nukes the FOUND_ROWS() database query
        add_filter('found_posts_query', array($this, 'filter_found_posts_query'), 5, 2);

        // Search and filter in EP_Posts to WP_Query
        add_filter('the_posts', array($this, 'filter_the_posts'), 10, 2);

        // Ensure we're in a loop before we allow blog switching
        add_action('loop_start', array($this, 'action_loop_start'), 10, 1);

        // Properly restore blog if necessary
        add_action('loop_end', array($this, 'action_loop_end'), 10, 1);

        // Properly switch to blog if necessary
        add_action('the_post', array($this, 'action_the_post'), 10, 1);
    }

    /**
     * Disables cache_results, adds header.
     *
     * @param $query
     * @since 0.9
     */
    public function action_pre_get_posts($query)
    {
        if (!ep_elasticpress_enabled($query) || apply_filters('ep_skip_query_integration', false, $query)) {
            return;
        }

        /**
         * `cache_results` defaults to false but can be enabled.
         *
         * @since 1.5
         */
        $query->set('cache_results', false);
        if (!empty($query->query['cache_results'])) {
            $query->set('cache_results', true);
        }

        if (!headers_sent()) {
            /**
             * Manually setting a header as $wp_query isn't yet initialized
             * when we call: add_filter('wp_headers', 'filter_wp_headers');
             */
            header('X-ElasticPress-Search: true');
        }
    }

    /**
     * Switch to the correct site if the post site id is different than the actual one
     *
     * @param array $post
     * @since 0.9
     */
    public function action_the_post($post)
    {
        if (!is_multisite()) {
            return;
        }

        if (empty($this->query_stack)) {
            return;
        }

        if (!ep_elasticpress_enabled($this->query_stack[0]) || apply_filters('ep_skip_query_integration', false,
                $this->query_stack[0])
        ) {
            return;
        }

        if (!empty($post->site_id) && get_current_blog_id() != $post->site_id) {
            restore_current_blog();

            switch_to_blog($post->site_id);

            remove_action('the_post', array($this, 'action_the_post'), 10, 1);
            setup_postdata($post);
            add_action('the_post', array($this, 'action_the_post'), 10, 1);
        }

    }

    /**
     * Ensure we've started a loop before we allow ourselves to change the blog
     *
     * @since 0.9.2
     */
    public function action_loop_start($query)
    {
        if (!is_multisite()) {
            return;
        }

        array_unshift($this->query_stack, $query);
    }

    /**
     * Make sure the correct blog is restored
     *
     * @since 0.9
     */
    public function action_loop_end($query)
    {
        if (!is_multisite()) {
            return;
        }

        array_pop($this->query_stack);

        if (!ep_elasticpress_enabled($query) || apply_filters('ep_skip_query_integration', false, $query)) {
            return;
        }

        if (!empty($GLOBALS['switched'])) {
            restore_current_blog();
        }
    }

    /**
     * Filter the posts array to contain ES search results in EP_Post form. Pull previously search posts.
     *
     * @param array $posts
     * @param object &$query
     * @return array
     */
    public function filter_the_posts($posts, &$query)
    {
        if (!ep_elasticpress_enabled($query) || apply_filters('ep_skip_query_integration', false,
                $query) || !isset($this->posts_by_query[spl_object_hash($query)])
        ) {
            return $posts;
        }

        $new_posts = $this->posts_by_query[spl_object_hash($query)];

        return $new_posts;
    }

    /**
     * Remove the found_rows from the SQL Query
     *
     * @param string $sql
     * @param object $query
     * @since 0.9
     * @return string
     */
    public function filter_found_posts_query($sql, $query)
    {
        if (!ep_elasticpress_enabled($query) || apply_filters('ep_skip_query_integration', false, $query)) {
            return $sql;
        }

        return '';
    }

    /**
     * Filter query string used for get_posts(). Search for posts and save for later.
     * Return a query that will return nothing.
     *
     * @param string $request
     * @param object $query
     * @since 0.9
     * @return string
     */
    public function filter_posts_request($request, $query)
    {
        if (!ep_elasticpress_enabled($query) || apply_filters('ep_skip_query_integration', false, $query)) {
            return $request;
        }

        $query_vars = $query->query_vars;
        if ('any' === $query_vars['post_type']) {

            if ($query->is_search()) {

                /*
                 * This is a search query
                 * To follow WordPress conventions,
                 * make sure we only search 'searchable' post types
                 */
                $searchable_post_types = get_post_types(array('exclude_from_search' => false));

                // If we have no searchable post types, there's no point going any further
                if (empty($searchable_post_types)) {

                    // Have to return something or it improperly calculates the found_posts
                    return "WHERE 0 = 1";
                }

                // Conform the post types array to an acceptable format for ES
                $post_types = array();
                foreach ($searchable_post_types as $type) {
                    $post_types[] = $type;
                }

                // These are now the only post types we will search
                $query_vars['post_type'] = $post_types;
            } else {
                /*
                 * This is not a search query
                 * so unset the post_type query var
                 */
                unset($query_vars['post_type']);
            }
        }

        $new_posts = apply_filters('ep_wp_query_search_cached_posts', array(), $query);

        if (count($new_posts) < 1) {

            $scope = 'all';
            if (!empty($query_vars['sites'])) {
                $scope = $query_vars['sites'];
            }

            $formatted_args = ep_format_args($query_vars);

            $search = ep_search($formatted_args, $scope);

            if (false === $search) {
                return $request;
            }

            $query->found_posts = $search['found_posts'];
            $query->max_num_pages = ceil($search['found_posts'] / $query->get('posts_per_page'));

            foreach ($search['posts'] as $post_array) {
                $post = new stdClass();

                $post->ID = $post_array['post_id'];
                $post->site_id = get_current_blog_id();

                if (!empty($post_array['site_id'])) {
                    $post->site_id = $post_array['site_id'];
                }
                // ep_search_request_args
                $post_return_args = apply_filters('ep_search_post_return_args',
                    array(
                        'post_type',
                        'post_author',
                        'post_name',
                        'post_status',
                        'post_title',
                        'post_parent',
                        'post_content',
                        'post_excerpt',
                        'post_date',
                        'post_date_gmt',
                        'post_modified',
                        'post_modified_gmt',
                        'post_mime_type',
                        'comment_count',
                        'comment_status',
                        'ping_status',
                        'menu_order',
                        'permalink',
                        'terms',
                        'post_meta',
                        'guid'
                    )
                );

                foreach ($post_return_args as $key) {
                    if ($key === 'post_author') {
                        $post->$key = $post_array[$key]['id'];
                    } elseif (isset($post_array[$key])) {
                        $post->$key = $post_array[$key];
                    }
                }

                $post->elasticsearch = true; // Super useful for debugging

                if ($post) {
                    $new_posts[] = $post;
                }
            }

            do_action('ep_wp_query_non_cached_search', $new_posts, $search, $query);
        }

        $this->posts_by_query[spl_object_hash($query)] = $new_posts;

        do_action('ep_wp_query_search', $new_posts, $search, $query);

        global $wpdb;

        return "SELECT * FROM $wpdb->posts WHERE 1=0";
    }
}

EP_WP_Query_Integration::factory();
