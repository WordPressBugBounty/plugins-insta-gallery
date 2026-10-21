<?php

namespace QuadLayers\IGG\Models;

use QuadLayers\WP_Orm\Builder\CollectionRepositoryBuilder;
use QuadLayers\IGG\Entity\Account;
use QuadLayers\IGG\Models\Feeds as Models_Feeds;

/**
 * Accounts repository. Persists the data as received: token normalization,
 * renewal and the token check cron live in Services\Account_Connection and
 * Services\Token_Renewal.
 */
class Accounts {

	protected static $instance;
	protected $repository;

	public function __construct() {
		$this->build_repository();
	}

	/**
	 * Drop the cached accounts, so the next write starts from the stored ones.
	 * Long processes (the daily token check waits for the proxy) would
	 * otherwise write back accounts changed meanwhile by another request.
	 */
	public function refresh() {
		wp_cache_delete( 'insta_gallery_accounts', 'options' );
		wp_cache_delete( 'alloptions', 'options' );
		$this->build_repository();
	}

	private function build_repository() {
		$builder = ( new CollectionRepositoryBuilder() )
		->setTable( 'insta_gallery_accounts' )
		->setEntity( Account::class )
		->setAutoIncrement( false );

		$this->repository = $builder->getRepository();
	}

	public function get_args() {
		return ( new Account() )->getDefaults();
	}

	public function get( string $id ) {
		$entity = $this->repository->find( $id );

		if ( ! $entity ) {
			return;
		}

		return $entity->getProperties();
	}

	public function delete( string $id ) {
		$status = $this->repository->delete( $id );
		if ( $status ) {
			Models_Feeds::instance()->clear_account_references( $id );
		}
		return $status;
	}

	public function update( string $id, array $account ) {
		$entity = $this->repository->update( $id, $account );

		if ( $entity ) {
			return $entity->getProperties();
		}
	}

	public function create( array $account ) {
		$entity = $this->repository->create( $account );

		if ( ! $entity ) {
			throw new \Exception( esc_html__( 'Error creating account.', 'insta-gallery' ), 400 );
		}

		return $entity->getProperties();
	}

	public function get_all() {
		$entities = $this->repository->findAll();
		if ( ! $entities ) {
			return;
		}
		$accounts = array();
		foreach ( $entities as $entity ) {
			$accounts[] = $entity->getProperties();
		}
		return $accounts;
	}

	public function delete_all() {
		return $this->repository->deleteAll();
	}

	public static function instance() {
		if ( ! isset( self::$instance ) ) {
			self::$instance = new self();
		}
		return self::$instance;
	}
}
