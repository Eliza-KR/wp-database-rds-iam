use Aws\Credentials\CredentialProvider;

class authenticator {
  /**
   * Authentication token.
   *
   * @var string
   */
  private $token = null;

  /**
   * Timestamp when the authentication token is expected to expire.
   *
   * @var integer
   */
  private $expires = time();

  /**
   * AWS region of the RDS cluster.
   *
   * @var string
   */
  private $awsRegion = null;

  /**
   * URL endpoint of the RDS cluster.
   *
   * @var string
   */
  private $clusterEndpoint = null;

  /**
   * Username of the database user configured for IAM authentication.
   *
   * @var string
   */
  private $dbUsername = null;

  /**
   * AWS credentials provider.
   *
   * @var CredentialProvider
   */
  private $provider = CredentialProvider::defaultProvider();

  /**
   * AWS RDS authentication generator
   *
   * @var AuthTokenGenerator
   */
  private $RdsAuthGenerator = new Aws\Rds\AuthTokenGenerator($provider);

  /**
   * Sets the authentication parameters.
   *
   * @param string  $awsRegion       AWS Region where the RDS cluster is located.
   * @param string  $clusterEndpoint URL endpoint of the RDS cluster.
   * @param string  $dbUsername      Username of the database user configured for IAM authentication.
   */
  public function __construct($awsRegion, $clusterEndpoint, $dbUsername) {
    $this->awsRegion       = $awsRegion;
    $this->clusterEndpoint = $clusterEndpoint;
    $this->dbUsername      = $dbUsername;
  }

  /**
   * Retrieves an authentication token.
   */
  public function authenticate() {
    if ( $this->token == null || time() >= $this->expires ) {
      $this->expires = time() + 15 * 60;
      $this->token = $RdsAuthGenerator->createToken($this->clusterEndpoint, $this->awsRegion, $this->dbUsername);
    }

    return $this->$token;
  }
}

class rdsdb extends wpdb {
  public function __construct( $dbuser, $dbname, $dbhost, $region ) {
    if ( WP_DEBUG && WP_DEBUG_DISPLAY) {
      $this->show_errors();
    }

    $this->dbuser     = $dbuser;
    $this->dbpassword = new authenticator($region, $dbhost, $dbuser)->authenticate;
    $this->dbname     = $dbname;
    $this->dbhost     = $dbhost;

    // wp-config.pho creation will manually connect when ready.
    if ( defined( 'WP_SETUP_CONFIG' ) ) {
      return;
    }

    $this->db_connect();
  }

  public function db_connect( $allow_bail = true ) {
    $this->is_mysql = true;

		/*
		 * Deprecated in 3.9+ when using MySQLi. No equivalent
		 * $new_link parameter exists for mysqli_* functions.
		 */
		$new_link     = defined( 'MYSQL_NEW_LINK' ) ? MYSQL_NEW_LINK : true;
		$client_flags = defined( 'MYSQL_CLIENT_FLAGS' ) ? MYSQL_CLIENT_FLAGS : 0;

		if ( $this->use_mysqli ) {
			/*
			 * Set the MySQLi error reporting off because WordPress handles its own.
			 * This is due to the default value change from `MYSQLI_REPORT_OFF`
			 * to `MYSQLI_REPORT_ERROR|MYSQLI_REPORT_STRICT` in PHP 8.1.
			 */
			mysqli_report( MYSQLI_REPORT_OFF );

			$this->dbh = mysqli_init();

			$host    = $this->dbhost;
			$port    = null;
			$socket  = null;
			$is_ipv6 = false;

			$host_data = $this->parse_db_host( $this->dbhost );
			if ( $host_data ) {
				list( $host, $port, $socket, $is_ipv6 ) = $host_data;
			}

			/*
			 * If using the `mysqlnd` library, the IPv6 address needs to be enclosed
			 * in square brackets, whereas it doesn't while using the `libmysqlclient` library.
			 * @see https://bugs.php.net/bug.php?id=67563
			 */
			if ( $is_ipv6 && extension_loaded( 'mysqlnd' ) ) {
				$host = "[$host]";
			}

			if ( WP_DEBUG ) {
				mysqli_real_connect( $this->dbh, $host, $this->dbuser, $this->dbpassword(), null, $port, $socket, $client_flags );
			} else {
				// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
				@mysqli_real_connect( $this->dbh, $host, $this->dbuser, $this->dbpassword(), null, $port, $socket, $client_flags );
			}

			if ( $this->dbh->connect_errno ) {
				$this->dbh = null;

				/*
				 * It's possible ext/mysqli is misconfigured. Fall back to ext/mysql if:
				 *  - We haven't previously connected, and
				 *  - WP_USE_EXT_MYSQL isn't set to false, and
				 *  - ext/mysql is loaded.
				 */
				$attempt_fallback = true;

				if ( $this->has_connected ) {
					$attempt_fallback = false;
				} elseif ( defined( 'WP_USE_EXT_MYSQL' ) && ! WP_USE_EXT_MYSQL ) {
					$attempt_fallback = false;
				} elseif ( ! function_exists( 'mysql_connect' ) ) {
					$attempt_fallback = false;
				}

				if ( $attempt_fallback ) {
					$this->use_mysqli = false;
					return $this->db_connect( $allow_bail );
				}
			}
		} else {
			if ( WP_DEBUG ) {
				$this->dbh = mysql_connect( $this->dbhost, $this->dbuser, $this->dbpassword(), $new_link, $client_flags );
			} else {
				// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
				$this->dbh = @mysql_connect( $this->dbhost, $this->dbuser, $this->dbpassword(), $new_link, $client_flags );
			}
		}

		if ( ! $this->dbh && $allow_bail ) {
			wp_load_translations_early();

			// Load custom DB error template, if present.
			if ( file_exists( WP_CONTENT_DIR . '/db-error.php' ) ) {
				require_once WP_CONTENT_DIR . '/db-error.php';
				die();
			}

			$message = '<h1>' . __( 'Error establishing a database connection' ) . "</h1>\n";

			$message .= '<p>' . sprintf(
				/* translators: 1: wp-config.php, 2: Database host. */
				__( 'This either means that the username and password information in your %1$s file is incorrect or that contact with the database server at %2$s could not be established. This could mean your host&#8217;s database server is down.' ),
				'<code>wp-config.php</code>',
				'<code>' . htmlspecialchars( $this->dbhost, ENT_QUOTES ) . '</code>'
			) . "</p>\n";

			$message .= "<ul>\n";
			$message .= '<li>' . __( 'Are you sure you have the correct username and password?' ) . "</li>\n";
			$message .= '<li>' . __( 'Are you sure you have typed the correct hostname?' ) . "</li>\n";
			$message .= '<li>' . __( 'Are you sure the database server is running?' ) . "</li>\n";
			$message .= "</ul>\n";

			$message .= '<p>' . sprintf(
				/* translators: %s: Support forums URL. */
				__( 'If you are unsure what these terms mean you should probably contact your host. If you still need help you can always visit the <a href="%s">WordPress Support Forums</a>.' ),
				__( 'https://wordpress.org/support/forums/' )
			) . "</p>\n";

			$this->bail( $message, 'db_connect_fail' );

			return false;
		} elseif ( $this->dbh ) {
			if ( ! $this->has_connected ) {
				$this->init_charset();
			}

			$this->has_connected = true;

			$this->set_charset( $this->dbh );

			$this->ready = true;
			$this->set_sql_mode();
			$this->select( $this->dbname, $this->dbh );

			return true;
		}

		return false;
  }
}

global $wpdb;

$dbuser = defined( 'DB_USER' ) ? DB_USER : '';
$dbname = defined( 'DB_NAME' ) ? DB_NAME : '';
$dbhost = defined( 'DB_HOST' ) ? DB_HOST : '';
$region = defined( 'AWS_REGION' ) ? AWS_REGION : '';
$wpdb = new altdb($dbuser, $dbname, $dbhost, $region);
