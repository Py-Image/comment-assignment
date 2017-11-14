<?php
/**
 * Contains modifications to the default WP_List_Table for the full Comments List
 *
 * @since		{{VERSION}}
 *
 * @package PYIS_Comment_Assignment
 * @subpackage PYIS_Comment_Assignment/core/admin
 */

defined( 'ABSPATH' ) || die();

final class PYIS_Comment_Assignment_Edit_Comments {
	
	public $comments_list_table;
	
	/**
	 * PYIS_Comment_Assignment_Edit_Comments constructor.
	 * 
	 * @since		{{VERSION}}
	 */
	function __construct() {
		
		add_filter( 'manage_edit-comments_columns', array( $this, 'get_columns' ) );
		
		add_action( 'manage_comments_custom_column', array( $this, 'assign_column' ), 10, 2 );
		
		// Inject User Assignment into Quick Edit screen
		add_action( 'init', array( $this, 'start_page_capture' ), 99 );
		add_action( 'shutdown', array( $this, 'add_assignment_to_quick_edit' ), 0 );
		
		add_action( 'wp_ajax_edit-comment', array( $this, 'wp_ajax_edit_comment' ), 1 );
		
		add_action( 'admin_enqueue_scripts', array( $this, 'admin_enqueue_scripts' ) );
		
		//add_filter( 'comment_row_actions', array( $this, 'comment_row_actions' ), 10, 2 );
		
	}
	
	/**
	 * WordPress does not provide us with a "normal" way to add columns to the Comments List Table
	 * By grabbing the Class itself using WP's internal methods, we can grab the in-use Columns and then append our own
	 * 
	 * @access		public
	 * @since		{{VERSION}}
	 * @return		array Columns
	 */
	public function get_columns() {
		
		$this->comments_list_table = _get_list_table( 'WP_Comments_List_Table' );
		
		// If we don't do this, it will show the Admin Avatar twice. This does not appear to happen with anything else
		remove_filter( 'comment_author', array( $this->comments_list_table, 'floated_admin_avatar' ) );
		
		$columns = $this->comments_list_table->get_columns();
		
		$columns['assigned_to'] = __( 'Assigned To', 'pyis-comment-assignment' );
		
		return $columns;
		
	}
	
	/**
	 * Place our own stuff within our custom Column
	 * 
	 * @param		string  $column_name Column Name
	 * @param		integer $comment_id  Comment ID
	 *                                      
	 * @access		public
	 * @since		{{VERSION}}
	 * @return		void
	 */
	public function assign_column( $column_name, $comment_id ) {
		
		if ( $column_name !== 'assigned_to' ) return;
		
		echo get_comment_meta( $comment_id, $column_name, true );
		
	}
	
	/**
	 * If we're on the generic Edit Comments screen, start an Object Buffer
	 * 
	 * @access		public
	 * @since		{{VERSION}}
	 * @return		void
	 */
	public function start_page_capture() {
		
		global $pagenow;
		
		if ( is_admin() && 
		   $pagenow == 'edit-comments.php' ) {
			ob_start();
		}
		
	}
	
	/**
	 * WordPress has literally no way to add to the Quick Edit screen for Comments
	 * This is the best that can be done while hopefully working into the foreseeable future
	 * We run some Regex after the Page has loaded on our Object Buffer and inject the modified <fieldset> into the Page.
	 * By doing it this way, we don't have to worry about JavaScript having any kind of nasty delay
	 * 
	 * @access		public
	 * @since		{{VERSION}}
	 * @return		void
	 */
	public function add_assignment_to_quick_edit() {
		
		global $pagenow;
		
		if ( ! is_admin() ||
		   $pagenow !== 'edit-comments.php' ) return;
		
		// Grab all the Users and build a Select Field
		$user_query = new WP_User_Query( array(
			'meta_key' => 'last_name',
			'orderby' => 'meta_value',
			'order' => 'ASC'
		) );
		
		$users = array();
		$select_field = '';
		if ( $user_query->get_total() > 0 ) {
			$users += wp_list_pluck( $user_query->get_results(), 'data', 'ID' );
		}
		
		$select_field .= '<select id="assigned-to-select">';
			$select_field .= '<option value="">' . __( 'Select a User', 'pyis-comment-assignment' ) . '</option>';
			foreach ( $users as $user_id => $user_data ) {
				$select_field .= '<option value="' . $user_id . '">' . $user_data->user_login . '</option>';
			}
		$select_field .= '</select>';
		
		// The Select Field is just for ease of use. The hidden Input field is what actually gets submitted by WordPress via AJAX
		$insert = '<div class="inside">';
			$insert .= '<label for="assigned-to">' . __( 'Assign', 'pyis-comment-assignment' ) . '</label>';
			$insert .= $select_field;
			$insert .= '<input type="hidden" id="assigned-to" name="assigned_to" value="" />';
		$insert .= '</div>';

		// Grab our Object Buffer
		$content = ob_get_clean();
		
		// Grab our <fieldset> from the Object Buffer
		// The "s" at the end is the DOT-ALL modifier. This allows us to match over line-breaks
		// Here's a good explaination: https://stackoverflow.com/a/2240607
		$match = preg_match( '#<fieldset class="comment-reply"(?:[^>]*)>(.*)<\/fieldset>#is', $content, $matches );
		
		// Remove any Line Breaks from the <fieldset> we just grabbed
		// If we remove the Line Breaks from the Object Buffer itself it produces errors for some reason
		$fields = preg_replace( "/\r|\n/", "", $matches[1] );
		
		// Place all of our injected fields after the last </div> in the <fieldset>
		$injected_fields = substr_replace( $fields, "{$insert}</div>", strrpos( $fields, '</div>' ), 6 );
		
		// Swap the <fieldset> if the Object Buffer with our modified one
		$content = preg_replace( '#<fieldset class="comment-reply"([^>]*)>(.*)<\/fieldset>#is', $injected_fields, $content );

		// Echo out the modified Object Buffer. This works kind of like a Filter, but it is technically an Action
		echo $content;
		
	}
	
	/**
	 * Hook into the AJAX Callback that the Quick Edit screen uses so that any changes to our Hidden Input are saved
	 * This fires off before WP Core's does, which means when it redraws the Table Row everything is taken care of for us
	 * 
	 * @access		public
	 * @since		{{VERSION}}
	 * @return		void
	 */
	public function wp_ajax_edit_comment() {
		
		check_ajax_referer( 'replyto-comment', '_ajax_nonce-replyto-comment' );

		$comment_id = (int) $_POST['comment_ID'];
		if ( ! current_user_can( 'edit_comment', $comment_id ) )
			wp_die( -1 );

		if ( '' == $_POST['content'] )
			wp_die( __( 'ERROR: please type a comment.' ) );
		
		// Allow unassignment
		if ( ! isset( $_POST['assigned_to'] ) || 
		   empty( $_POST['assigned_to'] ) ) {
			$delete = delete_comment_meta( $comment_id, 'assigned_to' );
		}
		
		$success = update_comment_meta( $comment_id, 'assigned_to', $_POST['assigned_to'] );
		
	}
	
	/**
	 * Enqueue Script to update the Hidden Input with the value of the Select Field
	 * 
	 * @access		public
	 * @since		{{VERSION}}
	 * @return		void
	 */
	public function admin_enqueue_scripts() {
		
		global $pagenow;
		
		if ( ! is_admin() ||
		   $pagenow !== 'edit-comments.php' ) return;
		
		wp_enqueue_script( 'pyis-comment-assignment-admin-edit-comments' );
		
	}
	
	public function comment_row_actions( $actions, $comment ) {
		
		$actions['assign'] = 'assign';
		
		return $actions;
		
	}
	
}

$instance = new PYIS_Comment_Assignment_Edit_Comments();