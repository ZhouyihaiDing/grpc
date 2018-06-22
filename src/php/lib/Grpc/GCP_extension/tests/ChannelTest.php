<?php
session_start();
header('Content-type: text/plain');

require_once(dirname(__FILE__).'/vendor/autoload.php');
require_once(dirname(__FILE__).'/../src/ChannelRef.php');
require_once(dirname(__FILE__).'/../src/GCPConfig.php');
require_once(dirname(__FILE__).'/../src/GCPCallInvoker.php');
require_once(dirname(__FILE__).'/../src/GCPExtensionChannel.php');
require_once(dirname(__FILE__).'/generated/Grpc/Gcp/AffinityConfig.php');
require_once(dirname(__FILE__).'/generated/Grpc/Gcp/AffinityConfig_Command.php');
require_once(dirname(__FILE__).'/generated/Grpc/Gcp/ApiConfig.php');
require_once(dirname(__FILE__).'/generated/Grpc/Gcp/ChannelPoolConfig.php');
require_once(dirname(__FILE__).'/generated/Grpc/Gcp/MethodConfig.php');
require_once(dirname(__FILE__).'/generated/GPBMetadata/GrpcGcp.php');

use Google\Cloud\Spanner\V1\SpannerGrpcClient;
use Google\Cloud\Spanner\V1\CreateSessionRequest;
use Google\Cloud\Spanner\V1\DeleteSessionRequest;
use Google\Auth\ApplicationDefaultCredentials;
use Google\Cloud\Spanner\V1\ExecuteSqlRequest;
use Google\Cloud\Spanner\V1\ListSessionsRequest;

class SimpleRequest
{
  private $data;
  public function __construct($data)
  {
    $this->data = $data;
  }
  public function setData($data)
  {
    $this->data = $data;
  }
  public function serializeToString()
  {
    return $this->data;
  }
}

class SimpleClient extends Grpc\BaseStub
{
  public function __construct($hostname, $opts, $channel = null)
  {
    parent::__construct($hostname, $opts, $channel);
  }

  public function SimpleUnary(
    SimpleRequest $argument,
    $metadata = [],
    $options = []
  ) {
    return $this->_simpleRequest(
      '/bind_unary_method',
      $argument,
      [],
      $metadata,
      $options
    );
  }

}

class CallCredentialsTest extends PHPUnit_Framework_TestCase
{
  public function setUp() {}


  public function tearDown()
  {
    // $_SESSION = array();
  }


  public function createSpannerStub($hostname = 'spanner.googleapis.com',
                                    $max_channels = 10,
                                    $max_streams = 1) {
    putenv("GOOGLE_APPLICATION_CREDENTIALS=./grpc-gcp.json");
    $this->_DEFAULT_MAX_CHANNELS_PER_TARGET = $max_channels;
    $this->_WATER_MARK = $max_streams;
    $string = file_get_contents("spanner.grpc.config");


    $conf = new \Grpc\Gcp\ApiConfig();
    $conf->mergeFromJsonString($string);
    $channel_pool = $conf->getChannelPool();
    $channel_pool->setMaxConcurrentStreamsLowWatermark($max_streams);

    $config = new \Grpc\GCP\Config($hostname, $conf);
    $credentials = \Grpc\ChannelCredentials::createSsl();
    $auth = ApplicationDefaultCredentials::getCredentials();
    $opts = [
      'credentials' => $credentials,
      'update_metadata' => $auth->getUpdateMetadataFunc(),
      'grpc_call_invoker' => $config->callInvoker(),
    ];

    $this->stub = new SpannerGrpcClient($hostname, $opts);
    $this->call_invoker = $config->callInvoker();

    $this->database = 'projects/grpc-gcp/instances/sample/databases/benchmark';
    $this->table = 'storage';
    $this->data = 'payload';
  }

  public function createStub() {
    putenv("GOOGLE_APPLICATION_CREDENTIALS=./grpc-gcp.json");
    $this->_DEFAULT_MAX_CHANNELS_PER_TARGET = 10;
    $this->_WATER_MARK = 1;
    $hostname = 'localhost:50051';
    $string = file_get_contents("spanner.grpc.config");
    $conf = new \Grpc\Gcp\ApiConfig();
    $conf->mergeFromJsonString($string);

    $config = new \Grpc\GCP\Config($hostname, $conf);
    $credentials = \Grpc\ChannelCredentials::createSsl();
    $auth = ApplicationDefaultCredentials::getCredentials();
    $opts = [
      'credentials' => $credentials,
      'update_metadata' => $auth->getUpdateMetadataFunc(),
      'grpc_call_invoker' => $config->callInvoker(),
    ];

    $this->stub = new SimpleClient($hostname, $opts);
    $this->call_invoker = $config->callInvoker();

    $this->database = 'projects/grpc-gcp/instances/sample/databases/benchmark';
    $this->table = 'storage';
    $this->data = 'payload';
  }


  public function assertStatusOk($status)
  {
    if ($status->code != \Grpc\STATUS_OK) {
      var_dump($status);
      throw new \Exception("gRPC status not OK: " . $status->code . "\n");
    }
  }

  public function assertConnecting($state) {
    $this->assertTrue($state == \GRPC\CHANNEL_CONNECTING ||
      $state == \GRPC\CHANNEL_TRANSIENT_FAILURE);
  }

  public function waitUntilNotIdle($channel) {
    for ($i = 0; $i < 1000; $i++) {
      echo "$i ";
      $now = \Grpc\Timeval::now();
      $deadline = $now->add(new \Grpc\Timeval(1000));
      if ($channel->watchConnectivityState(\GRPC\CHANNEL_IDLE,
        $deadline)) {
        return true;
      }
    }
    $this->assertTrue(false);
  }

  // Test CreateSession Reuse Channel
  public function testStubGetConnectivityState()
  {
    $this->createSpannerStub();
    $connection_state = $this->stub->getConnectivityState(false);
    $this->assertEquals(\Grpc\CHANNEL_IDLE, $connection_state);

    $create_session_request = new CreateSessionRequest();
    $create_session_request->setDatabase($this->database);
    $create_session_call = $this->stub->CreateSession($create_session_request);
    $connection_state = $this->stub->getConnectivityState(false);
    $this->assertEquals(\Grpc\CHANNEL_READY, $connection_state);

    list($session, $status) = $create_session_call->wait();
    $this->assertStatusOk($status);
    $connection_state = $this->stub->getConnectivityState(false);
    $this->assertEquals(\Grpc\CHANNEL_READY, $connection_state);

    $this->stub->close();
  }

  // Test CreateSession Reuse Channel
  public function testChannelGetConnectivityState()
  {
    $this->createStub('localhost:50051');
    $gcp_channel = $this->call_invoker->_getChannel();
    $connection_state = $gcp_channel->getConnectivityState(true);
    $this->assertEquals(\Grpc\CHANNEL_IDLE, $connection_state);
    $this->waitUntilNotIdle($gcp_channel);
    $connection_state = $gcp_channel->getConnectivityState();
    $this->assertConnecting($connection_state);
  }

  /**
   * @expectedException RuntimeException
   */
  public function testClose()
  {
    $this->createSpannerStub();
    $connection_state = $this->stub->getConnectivityState(false);
    $this->assertEquals(\Grpc\CHANNEL_IDLE, $connection_state);

    $create_session_request = new CreateSessionRequest();
    $create_session_request->setDatabase($this->database);
    $create_session_call = $this->stub->CreateSession($create_session_request);
    $connection_state = $this->stub->getConnectivityState(false);
    $this->assertEquals(\Grpc\CHANNEL_READY, $connection_state);


    $this->stub->close();
    $connection_state = $this->stub->getConnectivityState(false);
  }
}
