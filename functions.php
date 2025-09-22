<?php
add_theme_support('post-thumbnails');

/**
 * Registers new menus
 *
 * @return void
 */
add_action('init', 'register_new_menu');
function register_new_menu()
{
  register_nav_menus(
    array(
      'primary-menu' => __('Primary menu')
    )
  );
}

/**
 * Changes the REST API root URL to use the home URL as the base.
 *
 * @param string $url The complete URL including scheme and path.
 * @return string The REST API root URL.
 */
add_filter('rest_url', 'home_url_as_api_url');
function home_url_as_api_url($url)
{
  $url = str_replace(home_url(), site_url(), $url);
  return $url;
}

/**
 * Customize the preview button in the WordPress admin.
 *
 * This function modifies the preview link for a post to point to a headless client setup.
 *
 * @param string  $link Original WordPress preview link.
 * @param WP_Post $post Current post object.
 * @return string Modified headless preview link.
 */
add_filter( 'preview_post_link', 'set_headless_preview_link', 10, 2 );
function set_headless_preview_link( string $link, WP_Post $post ): string {
	// Set the front-end preview route.
  $frontendUrl = HEADLESS_URL;

	// Update the preview link in WordPress.
  return add_query_arg(
    [
      'secret' => HEADLESS_SECRET,
      'id' => $post->ID,
      'post_type' => $post->post_type,
    ],
    esc_url_raw( esc_url_raw( "$frontendUrl/api/preview" ))
  );
}

/**
 * Dynamically register REST preview filters for all public post types
 */
function register_headless_rest_preview_filters() {
  // Get all public post types
  $post_types = get_post_types(['public' => true], 'names');
  
  foreach ($post_types as $post_type) {
    // Register the filter for each post type
    add_filter("rest_prepare_{$post_type}", 'set_headless_rest_preview_link', 10, 2);
  }
}
add_action('rest_api_init', 'register_headless_rest_preview_filters');

add_filter( 'rest_prepare_page', 'set_headless_rest_preview_link', 10, 2 );
add_filter( 'rest_prepare_post', 'set_headless_rest_preview_link' , 10, 2 );
function set_headless_rest_preview_link( WP_REST_Response $response, WP_Post $post ): WP_REST_Response {
  // Check if the post status is 'draft' and set the preview link accordingly.
  if ( 'draft' === $post->post_status ) {
    $response->data['link'] = get_preview_post_link( $post );
    return $response;
  }

  // For published posts, modify the permalink to point to the frontend.
  if ( 'publish' === $post->post_status ) {

    // Get the post permalink.
    $permalink = get_permalink( $post );

    // Check if the permalink contains the site URL.
    if ( false !== stristr( $permalink, get_site_url() ) ) {

      $frontendUrl = HEADLESS_URL;

      // Replace the site URL with the frontend URL.
      $response->data['link'] = str_ireplace(
        get_site_url(),
        $frontendUrl,
        $permalink
      );
    }
  }

  return $response;
}


/**
 * Adds the headless_revalidate function to the save_post action hook.
 * This function makes a PUT request to the headless site' api/revalidate endpoint with JSON body: paths = ['/path/to/page', '/path/to/another/page']
 * Requires HEADLESS_URL and HEADLESS_SECRET to be defined in wp-config.php
 *
 * @param int $post_ID The ID of the post being saved.
 * @return void
 */

// add_action('transition_post_status', 'headless_revalidate', 10, 3);
function headless_revalidate(string $new_status, string $old_status, object $post ): void
{
  if ( ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) || ( defined( 'DOING_CRON' ) && DOING_CRON ) ) {
    return;
  }

  // Ignore drafts and inherited posts.
  if ( ( 'draft' === $new_status && 'draft' === $old_status ) || 'inherit' === $new_status ) {
    return;
  }

  $frontendUrl = HEADLESS_URL;
  $headlessSecret = HEADLESS_SECRET;

  $data = json_encode([
    'tags'  => ['wordpress'],
  ]);

  $response = wp_remote_request("$frontendUrl/api/revalidate/", [
    'method'  => 'PUT',
    'body'    => $data,
    'headers' => [
      'X-Headless-Secret-Key' => $headlessSecret,
      'Content-Type'  => 'application/json',
    ],
  ]);

  // Check if the request was successful
  if (is_wp_error($response)) {
    // Handle error
    error_log($response->get_error_message());
  }
}

function wsra_get_user_inputs()
{
  $pageNo = sprintf("%d", $_GET['pageNo']);
  $perPage = sprintf("%d", $_GET['perPage']);
  // Check for array key taxonomyType
  if (array_key_exists('taxonomyType', $_GET)) {
    $taxonomy = $_GET['taxonomyType'];
  } else {
    $taxonomy = 'category';
  }
  $postType = $_GET['postType'];
  $paged = $pageNo ? $pageNo : 1;
  $perPage = $perPage ? $perPage : 100;
  $offset = ($paged - 1) * $perPage;
  $args = array(
    'number' => $perPage,
    'offset' => $offset,
  );
  $postArgs = array(
    'posts_per_page' => $perPage,
    'post_type' => strval($postType ? $postType : 'post'),
    'paged' => $paged,
  );

  return [$args, $postArgs, $taxonomy];
}

function wsra_generate_author_api()
{
  [$args] = wsra_get_user_inputs();
  $author_urls = array();
  $authors =  get_users($args);
  foreach ($authors as $author) {
    $fullUrl = esc_url(get_author_posts_url($author->ID));
    $url = str_replace(home_url(), '', $fullUrl);
    $tempArray = [
      'url' => $url,
    ];
    array_push($author_urls, $tempArray);
  }
  return array_merge($author_urls);
}

function wsra_generate_taxonomy_api()
{
  [$args,, $taxonomy] = wsra_get_user_inputs();
  $taxonomy_urls = array();
  $taxonomys = $taxonomy == 'tag' ? get_tags($args) : get_categories($args);
  foreach ($taxonomys as $taxonomy) {
    $fullUrl = esc_url(get_category_link($taxonomy->term_id));
    $url = str_replace(home_url(), '', $fullUrl);
    $tempArray = [
      'url' => $url,
    ];
    array_push($taxonomy_urls, $tempArray);
  }
  return array_merge($taxonomy_urls);
}

function wsra_generate_posts_api()
{
  [, $postArgs] = wsra_get_user_inputs();
  $postUrls = array();
  $query = new WP_Query($postArgs);

  while ($query->have_posts()) {
    $query->the_post();
    $uri = str_replace(home_url(), '', get_permalink());
    $tempArray = [
      'url' => $uri,
      'post_modified_date' => get_the_modified_date(),
    ];
    array_push($postUrls, $tempArray);
  }
  wp_reset_postdata();
  return array_merge($postUrls);
}

function wsra_generate_totalpages_api()
{
  $args = array(
    'exclude_from_search' => false
  );
  $argsTwo = array(
    'publicly_queryable' => true
  );
  $post_types = get_post_types($args, 'names');
  $post_typesTwo = get_post_types($argsTwo, 'names');
  $post_types = array_merge($post_types, $post_typesTwo);
  unset($post_types['attachment']);
  $defaultArray = [
    'category' => count(get_categories()),
    'tag' => count(get_tags()),
    'user' => (int)count_users()['total_users'],
  ];
  $tempValueHolder = array();
  foreach ($post_types as $postType) {
    $tempValueHolder[$postType] = (int)wp_count_posts($postType)->publish;
  }
  return array_merge($defaultArray, $tempValueHolder);
}

add_action('rest_api_init', function () {
  register_rest_route('sitemap/v1', '/posts', array(
    'methods' => 'GET',
    'callback' => 'wsra_generate_posts_api',
  ));
});
add_action('rest_api_init', function () {
  register_rest_route('sitemap/v1', '/taxonomy', array(
    'methods' => 'GET',
    'callback' => 'wsra_generate_taxonomy_api',
  ));
});
add_action('rest_api_init', function () {
  register_rest_route('sitemap/v1', '/author', array(
    'methods' => 'GET',
    'callback' => 'wsra_generate_author_api',
  ));
});
add_action('rest_api_init', function () {
  register_rest_route('sitemap/v1', '/totalpages', array(
    'methods' => 'GET',
    'callback' => 'wsra_generate_totalpages_api',
  ));
});

add_action( 'graphql_register_types', function() {

	// This registers a connection to the Schema at the root of the Graph
	// The connection field name is "popularPosts"
	register_graphql_connection( [
		'fromType'           => 'RootQuery',
		'toType'             => 'Post',
		'fromFieldName'      => 'popularPosts', // This is the field name that will be exposed in the Schema to query this connection by
		'connectionTypeName' => 'RootQueryToPopularPostsConnection',
		'connectionArgs'     => \WPGraphQL\Connection\PostObjects::get_connection_args(), // This adds Post connection args to the connection
		'resolve'            => function( $root, $args, \WPGraphQL\AppContext $context, $info ) {

			$resolver = new \WPGraphQL\Data\Connection\PostObjectConnectionResolver( $root, $args, $context, $info );

			// Note, these args will override anything the user passes in as { where: { ... } } args in the GraphQL Query
			$resolver->set_query_arg( 'meta_key', 'wpb_post_views_count' );
			$resolver->set_query_arg( 'orderby', 'meta_value_num' );
			$resolver->set_query_arg( 'order', 'DESC' );

			return $resolver->get_connection();
		}
	] );

	// This registers a field to the "Post" type so we can query the "viewCount" and see the value of which posts have the most views
	register_graphql_field( 'Post', 'viewCount', [
		'type'    => 'Int',
		'resolve' => function( $post ) {
			return get_post_meta( $post->databaseId, 'wpb_post_views_count', true );
		}
	] );
});

add_action('graphql_register_types', function() {
  // Campo personalizado para URI headless en ContentNode
  register_graphql_field( 'ContentNode', 'headlessUri', [
    'type' => 'String',
    'resolve' => function( $contentNode ) {
      $permalink = get_permalink( $contentNode->databaseId );
      $frontendUrl = HEADLESS_URL;
      
      // Reemplaza la URL del sitio con la del frontend, similar a REST
      if ( false !== stristr( $permalink, get_site_url() ) ) {
        return str_ireplace( get_site_url(), $frontendUrl, $permalink );
      }
      
      return $permalink; // Fallback
    }
  ]);
});
