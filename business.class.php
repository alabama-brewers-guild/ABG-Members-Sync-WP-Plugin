<?php

class Business {
	private $post_business;
	private $post_business_meta;

	public $name;
	public $membership_status;

	public function __construct( $post_id ) {
		$this->post_business = get_post($post_id);
		$this->post_business_meta = get_post_meta($post_id);

		$this->name = $this->post_business->post_title;
		
		$this->membership_status = get_the_terms( $this->post_business->ID, 'membership_status' )[0]->name;
	}

	function Get_Membership_Levels() {
		$names = array();
		$terms = get_the_terms( $this->post_business->ID, 'membership_level' );
		if( $terms) {
			foreach( $terms as $item ) {
				array_push( $names, $item->name );
			}
		}
		return $names;
	}

	function Get_Business_Categories() {
		$names = array();
		$terms = get_the_terms( $this->post_business->ID, 'business_category' );
		if( $terms) {
			foreach( $terms as $item ) {
				array_push( $names, $item->name );
			}
		}
		return $names;
	}

	function Get_Private_Categories() {
		$names = array();
		$terms = get_the_terms( $this->post_business->ID, 'private_category' );
		if( $terms) {
			foreach( $terms as $item ) {
				array_push( $names, $item->name );
			}
		}
		return $names;
	}
}