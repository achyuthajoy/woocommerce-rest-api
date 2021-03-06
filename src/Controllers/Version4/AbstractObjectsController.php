<?php
/**
 * Abstract Rest CRUD Controller Class
 *
 * @package Automattic/WooCommerce/RestApi
 */

namespace Automattic\WooCommerce\RestApi\Controllers\Version4;

defined( 'ABSPATH' ) || exit;

use Automattic\WooCommerce\RestApi\Controllers\Version4\Utilities\Permissions;
use Automattic\WooCommerce\RestApi\Controllers\Version4\Utilities\Pagination;

/**
 * CRUD Object Controller.
 */
abstract class AbstractObjectsController extends AbstractController {

	/**
	 * If object is hierarchical.
	 *
	 * @var bool
	 */
	protected $hierarchical = false;

	/**
	 * Post type.
	 *
	 * @var string
	 */
	protected $post_type = '';

	/**
	 * Get object.
	 *
	 * @param  int $id Object ID.
	 * @return \WC_Data|bool
	 */
	abstract protected function get_object( $id );

	/**
	 * Prepares one object for create or update operation.
	 *
	 * @since  3.0.0
	 * @param  \WP_REST_Request $request Request object.
	 * @param  bool             $creating If is creating a new object.
	 * @return \WC_Data The prepared item, or \WP_Error object on failure.
	 */
	abstract protected function prepare_object_for_database( $request, $creating = false );

	/**
	 * Overwrite in extended class to declare support for eager loading of post objects.
	 *
	 * @return bool
	 */
	protected function support_eager_loading() {
		return false;
	}

	/**
	 * Register the routes for products.
	 */
	public function register_routes() {
		$this->register_items_route();
		$this->register_item_route();
		$this->register_batch_route();
	}

	/**
	 * Get a single item.
	 *
	 * @param \WP_REST_Request $request Full details about the request.
	 * @return \WP_Error|\WP_REST_Response
	 */
	public function get_item( $request ) {
		$object = $this->get_object( (int) $request['id'] );

		if ( ! $object || 0 === $object->get_id() ) {
			return new \WP_Error( "woocommerce_rest_{$this->post_type}_invalid_id", __( 'Invalid ID.', 'woocommerce-rest-api' ), array( 'status' => 404 ) );
		}

		$data     = $this->prepare_item_for_response( $object, $request );
		$response = rest_ensure_response( $data );

		return $response;
	}

	/**
	 * Save an object data.
	 *
	 * @since  3.0.0
	 * @param  \WP_REST_Request $request  Full details about the request.
	 * @param  bool             $creating If is creating a new object.
	 * @return \WC_Data|\WP_Error
	 */
	protected function save_object( $request, $creating = false ) {
		try {
			$object = $this->prepare_object_for_database( $request, $creating );

			if ( is_wp_error( $object ) ) {
				return $object;
			}

			$object->save();

			return $this->get_object( $object->get_id() );
		} catch ( \WC_Data_Exception $e ) {
			return new \WP_Error( $e->getErrorCode(), $e->getMessage(), $e->getErrorData() );
		} catch ( \WC_REST_Exception $e ) {
			return new \WP_Error( $e->getErrorCode(), $e->getMessage(), array( 'status' => $e->getCode() ) );
		}
	}

	/**
	 * Create a single item.
	 *
	 * @param \WP_REST_Request $request Full details about the request.
	 * @return \WP_Error|\WP_REST_Response
	 */
	public function create_item( $request ) {
		if ( ! empty( $request['id'] ) ) {
			/* translators: %s: post type */
			return new \WP_Error( "woocommerce_rest_{$this->post_type}_exists", sprintf( __( 'Cannot create existing %s.', 'woocommerce-rest-api' ), $this->post_type ), array( 'status' => 400 ) );
		}

		$object = $this->save_object( $request, true );

		if ( is_wp_error( $object ) ) {
			return $object;
		}

		try {
			$this->update_additional_fields_for_object( $object, $request );

			/**
			 * Fires after a single object is created or updated via the REST API.
			 *
			 * @param \WC_Data         $object    Inserted object.
			 * @param \WP_REST_Request $request   Request object.
			 * @param boolean         $creating  True when creating object, false when updating.
			 */
			do_action( "woocommerce_rest_insert_{$this->post_type}_object", $object, $request, true );
		} catch ( \WC_Data_Exception $e ) {
			$object->delete();
			return new \WP_Error( $e->getErrorCode(), $e->getMessage(), $e->getErrorData() );
		} catch ( \WC_REST_Exception $e ) {
			$object->delete();
			return new \WP_Error( $e->getErrorCode(), $e->getMessage(), array( 'status' => $e->getCode() ) );
		}

		$request->set_param( 'context', 'edit' );
		$response = $this->prepare_item_for_response( $object, $request );
		$response = rest_ensure_response( $response );
		$response->set_status( 201 );
		$response->header( 'Location', rest_url( sprintf( '/%s/%s/%d', $this->namespace, $this->rest_base, $object->get_id() ) ) );

		return $response;
	}

	/**
	 * Update a single post.
	 *
	 * @param \WP_REST_Request $request Full details about the request.
	 * @return \WP_Error|\WP_REST_Response
	 */
	public function update_item( $request ) {
		$object = $this->get_object( (int) $request['id'] );

		if ( ! $object || 0 === $object->get_id() ) {
			return new \WP_Error( "woocommerce_rest_{$this->post_type}_invalid_id", __( 'Invalid ID.', 'woocommerce-rest-api' ), array( 'status' => 404 ) );
		}

		$object = $this->save_object( $request, false );

		if ( is_wp_error( $object ) ) {
			return $object;
		}

		try {
			$this->update_additional_fields_for_object( $object, $request );

			/**
			 * Fires after a single object is created or updated via the REST API.
			 *
			 * @param \WC_Data         $object    Inserted object.
			 * @param \WP_REST_Request $request   Request object.
			 * @param boolean         $creating  True when creating object, false when updating.
			 */
			do_action( "woocommerce_rest_insert_{$this->post_type}_object", $object, $request, false );
		} catch ( \WC_Data_Exception $e ) {
			return new \WP_Error( $e->getErrorCode(), $e->getMessage(), $e->getErrorData() );
		} catch ( \WC_REST_Exception $e ) {
			return new \WP_Error( $e->getErrorCode(), $e->getMessage(), array( 'status' => $e->getCode() ) );
		}

		$request->set_param( 'context', 'edit' );
		$response = $this->prepare_item_for_response( $object, $request );
		return rest_ensure_response( $response );
	}

	/**
	 * Prepare objects query.
	 *
	 * @since  3.0.0
	 * @param  \WP_REST_Request $request Full details about the request.
	 * @return array
	 */
	protected function prepare_objects_query( $request ) {
		$args                        = array();
		$args['offset']              = $request['offset'];
		$args['order']               = $request['order'];
		$args['orderby']             = $request['orderby'];
		$args['paged']               = $request['page'];
		$args['post__in']            = $request['include'];
		$args['post__not_in']        = $request['exclude'];
		$args['posts_per_page']      = $request['per_page'];
		$args['name']                = $request['slug'];
		$args['post_parent__in']     = $request['parent'];
		$args['post_parent__not_in'] = $request['parent_exclude'];
		$args['s']                   = $request['search'];
		$args['fields']              = 'ids';

		if ( 'date' === $args['orderby'] ) {
			$args['orderby'] = 'date ID';
		}

		$args['date_query'] = array();

		// Set before into date query. Date query must be specified as an array of an array.
		if ( isset( $request['before'] ) ) {
			$args['date_query'][0]['before'] = $request['before'];
		}

		// Set after into date query. Date query must be specified as an array of an array.
		if ( isset( $request['after'] ) ) {
			$args['date_query'][0]['after'] = $request['after'];
		}

		// Set date query colummn. Defaults to post_date.
		if ( isset( $request['date_column'] ) && ! empty( $args['date_query'][0] ) ) {
			$args['date_query'][0]['column'] = 'post_' . $request['date_column'];
		}

		// Force the post_type argument, since it's not a user input variable.
		$args['post_type'] = $this->post_type;

		/**
		 * Filter the query arguments for a request.
		 *
		 * Enables adding extra arguments or setting defaults for a post
		 * collection request.
		 *
		 * @param array            $args    Key value array of query var to query value.
		 * @param \WP_REST_Request $request The request used.
		 */
		$args = apply_filters( "woocommerce_rest_{$this->post_type}_object_query", $args, $request );

		return $this->prepare_items_query( $args, $request );
	}

	/**
	 * Get objects. Works in two steps:
	 * 1. Get ID's and total count to return for current page. This is so that if we need to perform a join,
	 * we don't do it on whole table and just on specific IDs later on which should be faster.
	 * 2. Fetch all post data, metadata with joined table if we need to with the IDs for current page.
	 *
	 * @since  3.0.0
	 * @param  array $query_args Query args.
	 * @return array
	 */
	protected function get_objects( $query_args ) {
		$query    = new \WP_Query();
		$post_ids = $query->query( $query_args );
		$result   = array();

		$total_posts = $query->found_posts;
		if ( $total_posts < 1 ) {
			// Out-of-bounds, run the query again without LIMIT for total count.
			unset( $query_args['paged'] );
			$count_query = new \WP_Query();
			$count_query->query( $query_args );
			$total_posts = $count_query->found_posts;
		} elseif ( $this->support_eager_loading() ) {
			$posts = get_posts(
				array(
					'include'     => $post_ids,
					'post_type'   => $this->post_type,
					'post_status' => $query_args['post_status'] ?? 'any',
				)
			);

			// Preserve original sort order.
			$post_ids = array_flip( $post_ids );
			foreach ( $posts as $post ) {
				$result[ $post->ID ] = $post;
			}
			$result = array_values( array_replace( $post_ids, $result ) );
		} else {
			$result = $post_ids;
		}

		return array(
			'objects' => array_filter( array_map( array( $this, 'get_object' ), $result ) ),
			'total'   => (int) $total_posts,
			'pages'   => (int) ceil( $total_posts / (int) $query->query_vars['posts_per_page'] ),
		);
	}

	/**
	 * Get a collection of posts.
	 *
	 * @param \WP_REST_Request $request Full details about the request.
	 * @return \WP_REST_Response
	 */
	public function get_items( $request ) {
		$query_args    = $this->prepare_objects_query( $request );
		$query_results = $this->get_objects( $query_args );

		$objects = array();
		foreach ( $query_results['objects'] as $object ) {
			if ( ! Permissions::user_can_read( $this->post_type, $object->get_id() ) ) {
				continue;
			}

			$data      = $this->prepare_item_for_response( $object, $request );
			$objects[] = $this->prepare_response_for_collection( $data );
		}

		$total     = $query_results['total'];
		$max_pages = $query_results['pages'];

		$response = rest_ensure_response( $objects );
		$response = Pagination::add_pagination_headers( $response, $request, $total, $max_pages );

		return $response;
	}

	/**
	 * Delete a single item.
	 *
	 * @param \WP_REST_Request $request Full details about the request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function delete_item( $request ) {
		$force  = (bool) $request['force'];
		$object = $this->get_object( (int) $request['id'] );
		$result = false;

		if ( ! $object || 0 === $object->get_id() ) {
			return new \WP_Error( "woocommerce_rest_{$this->post_type}_invalid_id", __( 'Invalid ID.', 'woocommerce-rest-api' ), array( 'status' => 404 ) );
		}

		$supports_trash = $this->supports_trash( $object );

		if ( ! Permissions::user_can_delete( $this->post_type, $object->get_id() ) ) {
			/* translators: %s: post type */
			return new \WP_Error( "woocommerce_rest_user_cannot_delete_{$this->post_type}", sprintf( __( 'Sorry, you are not allowed to delete %s.', 'woocommerce-rest-api' ), $this->post_type ), array( 'status' => rest_authorization_required_code() ) );
		}

		$request->set_param( 'context', 'edit' );
		$previous = $this->prepare_item_for_response( $object, $request );

		// If we're forcing, then delete permanently.
		if ( $force ) {
			$object->delete( true );
			$result = 0 === $object->get_id();
		} else {
			// If we don't support trashing for this type, error out.
			if ( ! $supports_trash ) {
				/* translators: %s: post type */
				return new \WP_Error( 'woocommerce_rest_trash_not_supported', sprintf( __( 'The %s does not support trashing.', 'woocommerce-rest-api' ), $this->post_type ), array( 'status' => 501 ) );
			}

			// Otherwise, only trash if we haven't already.
			if ( is_callable( array( $object, 'get_status' ) ) && 'trash' === $object->get_status() ) {
				/* translators: %s: post type */
				return new \WP_Error( 'woocommerce_rest_already_trashed', sprintf( __( 'The %s has already been deleted.', 'woocommerce-rest-api' ), $this->post_type ), array( 'status' => 410 ) );
			} else {
				$object->delete();
				$result = is_callable( array( $object, 'get_status' ) ) ? 'trash' === $object->get_status() : true;
			}
		}

		if ( ! $result ) {
			/* translators: %s: post type */
			return new \WP_Error( 'woocommerce_rest_cannot_delete', sprintf( __( 'The %s cannot be deleted.', 'woocommerce-rest-api' ), $this->post_type ), array( 'status' => 500 ) );
		}

		$response = new \WP_REST_Response();
		$response->set_data(
			array(
				'deleted'  => true,
				'previous' => $previous->get_data(),
			)
		);

		/**
		 * Fires after a single object is deleted or trashed via the REST API.
		 *
		 * @param \WC_Data          $object   The deleted or trashed object.
		 * @param \WP_REST_Response $response The response data.
		 * @param \WP_REST_Request  $request  The request sent to the API.
		 */
		do_action( "woocommerce_rest_delete_{$this->post_type}_object", $object, $response, $request );

		return $response;
	}

	/**
	 * Can this object be trashed?
	 *
	 * @param  object $object Object to check.
	 * @return boolean
	 */
	protected function supports_trash( $object ) {
		$supports_trash = EMPTY_TRASH_DAYS > 0;

		/**
		 * Filter whether an object is trashable.
		 *
		 * Return false to disable trash support for the object.
		 *
		 * @param boolean $supports_trash Whether the object type support trashing.
		 * @param \WC_Data $object         The object being considered for trashing support.
		 */
		return apply_filters( "woocommerce_rest_{$this->post_type}_object_trashable", $supports_trash, $object );
	}

	/**
	 * Prepare links for the request.
	 *
	 * @param mixed            $item Object to prepare.
	 * @param \WP_REST_Request $request Request object.
	 * @return array
	 */
	protected function prepare_links( $item, $request ) {
		$links = array(
			'self'       => array(
				'href' => rest_url( sprintf( '/%s/%s/%d', $this->namespace, $this->rest_base, $item->get_id() ) ),
			),
			'collection' => array(
				'href' => rest_url( sprintf( '/%s/%s', $this->namespace, $this->rest_base ) ),
			),
		);

		return $links;
	}

	/**
	 * Get the query params for collections of attachments.
	 *
	 * @return array
	 */
	public function get_collection_params() {
		$params                       = array();
		$params['context']            = $this->get_context_param();
		$params['context']['default'] = 'view';

		$params['page'] = array(
			'description'       => __( 'Current page of the collection.', 'woocommerce-rest-api' ),
			'type'              => 'integer',
			'default'           => 1,
			'sanitize_callback' => 'absint',
			'validate_callback' => 'rest_validate_request_arg',
			'minimum'           => 1,
		);

		$params['per_page'] = array(
			'description'       => __( 'Maximum number of items to be returned in result set.', 'woocommerce-rest-api' ),
			'type'              => 'integer',
			'default'           => 10,
			'minimum'           => 1,
			'maximum'           => 100,
			'sanitize_callback' => 'absint',
			'validate_callback' => 'rest_validate_request_arg',
		);

		$params['search'] = array(
			'description'       => __( 'Limit results to those matching a string.', 'woocommerce-rest-api' ),
			'type'              => 'string',
			'sanitize_callback' => 'sanitize_text_field',
			'validate_callback' => 'rest_validate_request_arg',
		);

		$params['after'] = array(
			'description'       => __( 'Limit response to resources created after a given ISO8601 compliant date.', 'woocommerce-rest-api' ),
			'type'              => 'string',
			'format'            => 'date-time',
			'validate_callback' => 'rest_validate_request_arg',
		);

		$params['before'] = array(
			'description'       => __( 'Limit response to resources created before a given ISO8601 compliant date.', 'woocommerce-rest-api' ),
			'type'              => 'string',
			'format'            => 'date-time',
			'validate_callback' => 'rest_validate_request_arg',
		);

		$params['date_column'] = array(
			'description'       => __( 'When limiting response using after/before, which date column to compare against.', 'woocommerce-rest-api' ),
			'type'              => 'string',
			'default'           => 'date',
			'enum'              => array(
				'date',
				'date_gmt',
				'modified',
				'modified_gmt',
			),
			'validate_callback' => 'rest_validate_request_arg',
		);

		$params['modified_before'] = array(
			'description'       => __( 'Limit response to resources modified before a given ISO8601 compliant date.', 'woocommerce-rest-api' ),
			'type'              => 'string',
			'format'            => 'date-time',
			'validate_callback' => 'rest_validate_request_arg',
		);

		$params['exclude'] = array(
			'description'       => __( 'Ensure result set excludes specific IDs.', 'woocommerce-rest-api' ),
			'type'              => 'array',
			'items'             => array(
				'type' => 'integer',
			),
			'default'           => array(),
			'sanitize_callback' => 'wp_parse_id_list',
		);

		$params['include'] = array(
			'description'       => __( 'Limit result set to specific ids.', 'woocommerce-rest-api' ),
			'type'              => 'array',
			'items'             => array(
				'type' => 'integer',
			),
			'default'           => array(),
			'sanitize_callback' => 'wp_parse_id_list',
		);

		$params['offset'] = array(
			'description'       => __( 'Offset the result set by a specific number of items.', 'woocommerce-rest-api' ),
			'type'              => 'integer',
			'sanitize_callback' => 'absint',
			'validate_callback' => 'rest_validate_request_arg',
		);

		$params['order'] = array(
			'description'       => __( 'Order sort attribute ascending or descending.', 'woocommerce-rest-api' ),
			'type'              => 'string',
			'default'           => 'desc',
			'enum'              => array( 'asc', 'desc' ),
			'validate_callback' => 'rest_validate_request_arg',
		);

		$params['orderby'] = array(
			'description'       => __( 'Sort collection by object attribute.', 'woocommerce-rest-api' ),
			'type'              => 'string',
			'default'           => 'date',
			'enum'              => array(
				'date',
				'modified',
				'id',
				'include',
				'title',
				'slug',
			),
			'validate_callback' => 'rest_validate_request_arg',
		);

		if ( $this->hierarchical ) {
			$params['parent'] = array(
				'description'       => __( 'Limit result set to those of particular parent IDs.', 'woocommerce-rest-api' ),
				'type'              => 'array',
				'items'             => array(
					'type' => 'integer',
				),
				'sanitize_callback' => 'wp_parse_id_list',
				'default'           => array(),
			);

			$params['parent_exclude'] = array(
				'description'       => __( 'Limit result set to all items except those of a particular parent ID.', 'woocommerce-rest-api' ),
				'type'              => 'array',
				'items'             => array(
					'type' => 'integer',
				),
				'sanitize_callback' => 'wp_parse_id_list',
				'default'           => array(),
			);
		}

		/**
		 * Filter collection parameters for the posts controller.
		 *
		 * The dynamic part of the filter `$this->post_type` refers to the post
		 * type slug for the controller.
		 *
		 * This filter registers the collection parameter, but does not map the
		 * collection parameter to an internal \WP_Query parameter. Use the
		 * `rest_{$this->post_type}_query` filter to set \WP_Query parameters.
		 *
		 * @param array        $query_params JSON Schema-formatted collection parameters.
		 * @param \WP_Post_Type $post_type    Post type object.
		 */
		return apply_filters( "rest_{$this->post_type}_collection_params", $params, $this->post_type );
	}

	/**
	 * Determine the allowed query_vars for a get_items() response and
	 * prepare for \WP_Query.
	 *
	 * @param array            $prepared_args Prepared arguments.
	 * @param \WP_REST_Request $request Request object.
	 * @return array           $query_args
	 */
	protected function prepare_items_query( $prepared_args = array(), $request = null ) {
		$valid_vars = array_flip( $this->get_allowed_query_vars() );
		$query_args = array();
		foreach ( $valid_vars as $var => $index ) {
			if ( isset( $prepared_args[ $var ] ) ) {
				/**
				 * Filter the query_vars used in `get_items` for the constructed query.
				 *
				 * The dynamic portion of the hook name, $var, refers to the query_var key.
				 *
				 * @param mixed $prepared_args[ $var ] The query_var value.
				 */
				$query_args[ $var ] = apply_filters( "woocommerce_rest_query_var_{$var}", $prepared_args[ $var ] );
			}
		}

		$query_args['ignore_sticky_posts'] = true;

		if ( 'include' === $query_args['orderby'] ) {
			$query_args['orderby'] = 'post__in';
		} elseif ( 'id' === $query_args['orderby'] ) {
			$query_args['orderby'] = 'ID'; // ID must be capitalized.
		} elseif ( 'slug' === $query_args['orderby'] ) {
			$query_args['orderby'] = 'name';
		}

		return $query_args;
	}

	/**
	 * Get all the WP Query vars that are allowed for the API request.
	 *
	 * @return array
	 */
	protected function get_allowed_query_vars() {
		global $wp;

		/**
		 * Filter the publicly allowed query vars.
		 *
		 * Allows adjusting of the default query vars that are made public.
		 *
		 * @param array  Array of allowed \WP_Query query vars.
		 */
		$valid_vars = apply_filters( 'query_vars', $wp->public_query_vars );

		$post_type_obj = get_post_type_object( $this->post_type );
		if ( current_user_can( $post_type_obj->cap->edit_posts ) ) {
			/**
			 * Filter the allowed 'private' query vars for authorized users.
			 *
			 * If the user has the `edit_posts` capability, we also allow use of
			 * private query parameters, which are only undesirable on the
			 * frontend, but are safe for use in query strings.
			 *
			 * To disable anyway, use
			 * `add_filter( 'woocommerce_rest_private_query_vars', '__return_empty_array' );`
			 *
			 * @param array $private_query_vars Array of allowed query vars for authorized users.
			 * }
			 */
			$private    = apply_filters( 'woocommerce_rest_private_query_vars', $wp->private_query_vars );
			$valid_vars = array_merge( $valid_vars, $private );
		}
		// Define our own in addition to WP's normal vars.
		$rest_valid = array(
			'date_query',
			'ignore_sticky_posts',
			'offset',
			'post__in',
			'post__not_in',
			'post_parent',
			'post_parent__in',
			'post_parent__not_in',
			'posts_per_page',
			'meta_query',
			'tax_query',
			'meta_key',
			'meta_value',
			'meta_compare',
			'meta_value_num',
		);
		$valid_vars = array_merge( $valid_vars, $rest_valid );

		/**
		 * Filter allowed query vars for the REST API.
		 *
		 * This filter allows you to add or remove query vars from the final allowed
		 * list for all requests, including unauthenticated ones. To alter the
		 * vars for editors only.
		 *
		 * @param array {
		 *    Array of allowed \WP_Query query vars.
		 *
		 *    @param string $allowed_query_var The query var to allow.
		 * }
		 */
		$valid_vars = apply_filters( 'woocommerce_rest_query_vars', $valid_vars );

		return $valid_vars;
	}

	/**
	 * Add meta query.
	 *
	 * @since 3.0.0
	 * @param array $args       Query args.
	 * @param array $meta_query Meta query.
	 * @return array
	 */
	protected function add_meta_query( $args, $meta_query ) {
		if ( empty( $args['meta_query'] ) ) {
			$args['meta_query'] = []; // phpcs:ignore
		}

		$args['meta_query'][] = $meta_query;

		return $args['meta_query'];
	}

	/**
	 * Return suffix for item action hooks.
	 *
	 * @return string
	 */
	protected function get_hook_suffix() {
		return $this->post_type . '_object';
	}

	/**
	 * Check if a given request has access to read a webhook.
	 *
	 * @param  \WP_REST_Request $request Full details about the request.
	 * @return \WP_Error|boolean
	 */
	public function get_item_permissions_check( $request ) {
		$id     = $request->get_param( 'id' );
		$object = $this->get_object( $id );

		if ( ! $object || 0 === $object->get_id() ) {
			return new \WP_Error( "woocommerce_rest_{$this->post_type}_invalid_id", __( 'Invalid ID.', 'woocommerce-rest-api' ), array( 'status' => 404 ) );
		}

		return parent::get_item_permissions_check( $request );
	}

	/**
	 * Check if a given request has access update a webhook.
	 *
	 * @param  \WP_REST_Request $request Full details about the request.
	 *
	 * @return bool|\WP_Error
	 */
	public function update_item_permissions_check( $request ) {
		$id     = $request->get_param( 'id' );
		$object = $this->get_object( $id );

		if ( ! $object || 0 === $object->get_id() ) {
			return new \WP_Error( "woocommerce_rest_{$this->post_type}_invalid_id", __( 'Invalid ID.', 'woocommerce-rest-api' ), array( 'status' => 404 ) );
		}

		return parent::update_item_permissions_check( $request );
	}

	/**
	 * Check if a given request has access delete a webhook.
	 *
	 * @param  \WP_REST_Request $request Full details about the request.
	 *
	 * @return bool|\WP_Error
	 */
	public function delete_item_permissions_check( $request ) {
		$id     = $request->get_param( 'id' );
		$object = $this->get_object( $id );

		if ( ! $object || 0 === $object->get_id() ) {
			return new \WP_Error( "woocommerce_rest_{$this->post_type}_invalid_id", __( 'Invalid ID.', 'woocommerce-rest-api' ), array( 'status' => 404 ) );
		}

		return parent::delete_item_permissions_check( $request );
	}
}
