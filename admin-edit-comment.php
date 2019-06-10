<?php
/*
 * Plugin Name: Admin Edit Comment
 * Description: Adding an extra comment functionality in post screen exclusively for your editorial team.
 * Version: 1.2.0
 * Author: PRESSMAN
 * Author URI: https://www.pressman.ne.jp/
 * License: GNU GPL v2 or higher
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 *
 * @author    PRESSMAN
 * @link      https://www.pressman.ne.jp/
 * @copyright Copyright (c) 2018, PRESSMAN
 */

// Deny accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Main Admin Edit Comment Class.
 *
 * @class Admin_Edit_Comment
 */
class Admin_Edit_Comment {

	const POST_TYPE_NAME             = 'admin_edit_comment';
	const ADMIN_EDIT_COMMENT_OPTIONS = 'admin_edit_comment_options';

	/**
	 * This plugin is enabled by default on 'post' and 'page'.
	 */
	const DEFAULT_ACTIVE_POST_TYPE = [ 'post', 'page' ];

	/**
	 * Version of this plugin.
	 *
	 * @var string
	 */
	public $version;

	/**
	 * The single instance of the class.
	 *
	 * @var Admin_Edit_Comment
	 */
	protected static $instance = null;

	/**
	 * Ensures only one instance of this class.
	 *
	 * @return Admin_Edit_Comment
	 */
	public static function instance() {
		if ( is_null( self::$instance ) ) {
			self::$instance = new self();
		}

		return self::$instance;
	}

	/**
	 * Admin_Edit_Comment constructor.
	 */
	public function __construct() {

		require_once plugin_dir_path( __FILE__ ) . 'admin/admin.php';

		register_uninstall_hook( __FILE__, 'aec_uninstall' );

		$plugin_data   = get_file_data( __FILE__, [ 'version' => 'Version' ] );
		$this->version = $plugin_data['version'];
		add_action( 'init', [ $this, 'register_post_type' ] );
		add_action( 'admin_head', [ $this, 'add_comment_box' ] );
		add_action( 'wp_ajax_aec_insert_comment', [ $this, 'insert_comment' ] );
		add_action( 'wp_ajax_aec_delete_comment', [ $this, 'delete_comment' ] );
		add_action( 'plugins_loaded', [ $this, 'load_text_domain' ] );
	}

	/**
	 * Loads translated strings.
	 */
	public function load_text_domain() {
		load_plugin_textdomain( 'admin-edit-comment', false, plugin_basename( plugin_dir_path( __FILE__ ) ) . '/languages' );
	}

	/**
	 * Register post type used by this plugin.
	 */
	public function register_post_type() {
		register_post_type(
			self::POST_TYPE_NAME,
			apply_filters( 'aec_register_post_type_args', [
				'label'        => 'AEC',
				'public'       => false,
				'hierarchical' => false,
				'supports'     => false,
				'rewrite'      => false,
			] )
		);
	}

	/**
	 * Adding comment box at editing screen.
	 */
	public function add_comment_box() {
		$screen            = get_current_screen();
		$active_post_types = apply_filters( 'aec_activate_post_types', get_option( self::ADMIN_EDIT_COMMENT_OPTIONS, self::DEFAULT_ACTIVE_POST_TYPE ) );
		if ( $screen->base !== 'post' || ! in_array( $screen->post_type, $active_post_types ) ) {
			return;
		}

		add_meta_box( 'admin_edit_comment', 'Admin Edit Comment', [ $this, 'add_meta_box' ], $active_post_types, 'side' );
		wp_enqueue_style( 'aec_edit.css', plugin_dir_url( __FILE__ ) . 'assets/css/edit.css', [], $this->version );
		wp_enqueue_script( 'aec_edit.js', plugin_dir_url( __FILE__ ) . 'assets/js/edit.js', [ 'jquery' ], $this->version, true );
		wp_localize_script(
			'aec_edit.js',
			'localize',
			[
				'ajax_url'           => admin_url( 'admin-ajax.php' ),
				'delete_failed_msg'  => __( 'Delete failed.', 'admin-edit-comment' ),
				'update_failed_msg'  => __( 'Update failed.', 'admin-edit-comment' ),
				'comments_limit_msg' => __( 'The number of comments exceeds the limit.', 'admin-edit-comment' ),
				'no_empty_msg'       => __( 'No empty.', 'admin-edit-comment' ),
			]
		);
	}

	/**
	 * Create meta box.
	 *
	 * @param WP_Post $post
	 */
	public function add_meta_box( WP_Post $post ) {
		?>
		<div id="aec_comment_wrap">
			<?php echo $this->get_content_html( $post->ID ); ?>
		</div>
		<div id='aec_text_area_wrap'>
			<textarea name='aec_comment_text_area' placeholder='' rows='3'></textarea>
		</div>
		<div id='aec_submit_wrap'>
			<input class='button button-primary' type='button' name='aec_submit' value='Send'>
		</div>
		<?php
	}

	/**
	 * Get comments content.
	 *
	 * @param int $post_id
	 *
	 * @return string
	 */
	private function get_content_html( $post_id ) {
		$comments = get_posts( apply_filters( 'aec_get_post_args', [
			'posts_per_page' => - 1,
			'post_type'      => self::POST_TYPE_NAME,
			'post_parent'    => $post_id,
		] ) );

		if ( ! $comments ) {
			return __( 'No comments yet.', 'admin-edit-comment' );
		}

		$limit           = ( count( $comments ) >= apply_filters( 'aec_limit_per_post', 20 ) ) ? 'exceeds' : '';
		$content_content = '<input type="hidden" name="aec_limit" value="' . $limit . '">';

		foreach ( $comments as $comment ) {
			$comment_text = nl2br( htmlspecialchars( $comment->post_content, ENT_QUOTES, 'UTF-8' ) );
			if ( (int) wp_get_current_user()->ID === (int) $comment->post_author ) {
				$delete_button = '<span class="aec_delete dashicons dashicons-trash" href=\'javascript:void(0)\' comment_id="' . $comment->ID . '"></span>';
				$is_others     = '';
				$author_name   = wp_get_current_user()->display_name;
				$avatar        = get_avatar( wp_get_current_user()->ID, 18 );
			} else {
				$delete_button = '';
				$is_others     = 'others';
				$author        = get_user_by( 'id', $comment->post_author );
				$author_name   = ( $author ) ? $author->display_name : '';
				$avatar        = get_avatar( $comment->post_author, 18 );
			}

			$content_content
				.= <<<HTML
				<div id="admin_edit_comment-{$comment->ID}">
					<div class="comment_head {$is_others}">
						<div class="comment_author">
							{$avatar}<strong class="comment_author_name">{$author_name}</strong>
						</div>
					</div>
					<div class="comment_body {$is_others}">{$comment_text}</div>
					<div class="comment_date">{$comment->post_date}{$delete_button}</div>
				</div>
HTML;
		}

		return $content_content;
	}

	/**
	 * Insert comment.
	 */
	public function insert_comment() {
		$post_id = filter_input( INPUT_POST, 'post_id' );
		$comment = filter_input( INPUT_POST, 'comment' );
		if ( ! $post_id || ! $comment ) {
			wp_send_json_error( [ 'message' => __( 'Oops! Failed to get necessary parameter.', 'admin-edit-comment' ) ] );
		}

		$user = wp_get_current_user();
		if ( ! $insert_post_id = wp_insert_post( apply_filters( 'aec_insert_post_args', [
			'post_author'  => $user->ID,
			'post_content' => $comment,
			'post_status'  => 'publish',
			'post_parent'  => $post_id,
			'post_type'    => self::POST_TYPE_NAME,
		] ) ) ) {
			wp_send_json_error( [ 'message' => __( 'Insert comment refused.', 'admin-edit-comment' ) ] );
		}

		/**
		 * Fires immediately after a comment is registered.
		 *
		 * @since 1.0.0
		 *
		 * @param string $post_id
		 * @param WP_User $user
		 * @param int $insert_post_id
		 */
		do_action( 'aec_after_insert_comment', $post_id, $user, $insert_post_id );

		wp_send_json_success( [ 'comments' => $this->get_content_html( $post_id ) ] );
	}

	/**
	 * Delete comment.
	 */
	public function delete_comment() {
		$post_id         = filter_input( INPUT_POST, 'post_id' );
		$comment_post_id = filter_input( INPUT_POST, 'comment_id' );
		if ( ! $post_id || ! $comment_post_id ) {
			wp_send_json_error( [ 'message' => __( 'WTH! Failed to get necessary parameter.', 'admin-edit-comment' ) ] );
		}

		if ( ! wp_delete_post( $comment_post_id, true ) ) {
			wp_send_json_error( [ 'message' => __( 'Failed to delete comment.', 'admin-edit-comment' ) ] );
		}

		wp_send_json_success( [ 'comments' => $this->get_content_html( $post_id ) ] );
	}
}

Admin_Edit_Comment::instance();

/**
 * Uninstalls Admin Edit Comment.
 */
function aec_uninstall() {
	if ( is_multisite() ) {
		$sites = get_sites();
		foreach ( $sites as $site ) {
			switch_to_blog( $site->blog_id );
			delete_option( Admin_Edit_Comment::ADMIN_EDIT_COMMENT_OPTIONS );
			restore_current_blog();
		}
	} else {
		delete_option( Admin_Edit_Comment::ADMIN_EDIT_COMMENT_OPTIONS );
	}
}
