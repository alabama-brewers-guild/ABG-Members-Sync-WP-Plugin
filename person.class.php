<?php
/*ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);*/

class Person {

	private $post_person;
	private $post_person_meta;

	public $ID;
	public $name;
	public $email;
	public $title;

	public function __construct( $post_id ) {

		$this->post_person = get_post($post_id);
		$this->post_person_meta = get_post_meta($post_id);

		$this->ID = $this->post_person->ID;
		$this->name = $this->post_person->post_title;
		if( array_key_exists( '_cdcrm_title', $this->post_person_meta ) ) {
			$this->title = $this->post_person_meta['_cdcrm_title'][0];
		}
		else {
			$this->title = '';
		}
		if( array_key_exists( '_cdcrm_email', $this->post_person_meta ) ) {
			$this->email = unserialize($this->post_person_meta['_cdcrm_email'][0])[0]["emailaddress"];
		}
		else {
			$this->email = '';
		}
		
	}

	public function Get_People_Categories() {
		$categories = array();
		$names = array();
		$terms = get_the_terms( $this->post_person->ID, 'people_category' );
		if( $terms) {
			foreach( $terms as $item ) {
				array_push( $names, $item->name );
			}
		}
		return $names;
	}

	public function Get_Connected_Businesses() {
		$businesses = array();

		$args['to'] = $this->post_person->ID;
		$connected = p2p_get_connections('businesses_to_people', $args);

		foreach($connected as $conn) {
			$conn_id = $conn->p2p_id;
			$conn_from = $conn->p2p_from;
			$conn_to = $conn->p2p_to;

			$conn_meta = p2p_get_meta($conn_id);

			$business = new Business( $conn_from );

			array_push( $businesses, $business );
		}
		return $businesses;        
	}

	public function Get_Roles() {
		$roles = array();

		$args['to'] = $this->post_person->ID;
		$connected = p2p_get_connections('businesses_to_people', $args);
		foreach($connected as $conn) {
			$id = $conn->p2p_id;
			array_push($roles, p2p_get_meta($id, 'role', true));
		}
		// Remove any blank entries.
		return array_filter($roles);
	}
}