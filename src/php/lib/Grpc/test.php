<?php


require_once(dirname(__FILE__).'/vendor/autoload.php');
require_once(dirname(__FILE__).'/BaseStub.php');
require_once(dirname(__FILE__).'/AbstractCall.php');
require_once(dirname(__FILE__).'/UnaryCall.php');
require_once(dirname(__FILE__).'/ClientStreamingCall.php');
require_once(dirname(__FILE__).'/ServerStreamingCall.php');
require_once(dirname(__FILE__).'/Interceptor.php');
require_once(dirname(__FILE__).'/CustomChannel.php');
require_once(dirname(__FILE__).'/Interceptor.php');
require_once(dirname(__FILE__).'/Internal/InterceptorChannel.php');
require_once(dirname(__FILE__).'/GCPExtension.php');

use Google\Cloud\Spanner\V1\SpannerGrpcClient;
use Google\Cloud\Spanner\V1\BeginTransactionRequest;
use Google\Cloud\Spanner\V1\CommitRequest;
use Google\Cloud\Spanner\V1\CommitResponse;
use Google\Cloud\Spanner\V1\CreateSessionRequest;
use Google\Cloud\Spanner\V1\DeleteSessionRequest;
use Google\Cloud\Spanner\V1\ExecuteSqlRequest;
use Google\Cloud\Spanner\V1\GetSessionRequest;
use Google\Cloud\Spanner\V1\KeySet;
use Google\Cloud\Spanner\V1\ListSessionsRequest;
use Google\Cloud\Spanner\V1\ListSessionsResponse;
use Google\Cloud\Spanner\V1\Mutation;
use Google\Cloud\Spanner\V1\PartialResultSet;
use Google\Cloud\Spanner\V1\PartitionOptions;
use Google\Cloud\Spanner\V1\PartitionQueryRequest;
use Google\Cloud\Spanner\V1\PartitionReadRequest;
use Google\Cloud\Spanner\V1\PartitionResponse;
use Google\Cloud\Spanner\V1\ReadRequest;
use Google\Cloud\Spanner\V1\ResultSet;
use Google\Cloud\Spanner\V1\RollbackRequest;
use Google\Cloud\Spanner\V1\Session;
use Google\Cloud\Spanner\V1\Transaction;
use Google\Cloud\Spanner\V1\TransactionOptions;
use Google\Cloud\Spanner\V1\TransactionSelector;
use Google\Protobuf\GPBEmpty;
use Google\Protobuf\Struct;

use Google\Auth\ApplicationDefaultCredentials;

echo "==============================\n";
$string = file_get_contents("spanner.grpc.config");
$conf = new Grpc_gcp\ExtensionConfig();

$conf->mergeFromJsonString($string);

$hostname = 'spanner.googleapis.com';
$credentials = \Grpc\ChannelCredentials::createSsl();
$auth = ApplicationDefaultCredentials::getCredentials();
$opts = [
  'credentials' => $credentials,
  'update_metadata' => $auth->getUpdateMetadataFunc(),
];

$gcp_channel = \Grpc\enable_grpc_gcp($conf);
$stub = new SpannerGrpcClient($hostname, $opts, $gcp_channel);

$database = 'projects/ddyihai-firestore/instances/test-instance/databases/test-database';

$create_session_request = new CreateSessionRequest();
$create_session_request->setDatabase($database);
$create_session_call = $stub->CreateSession($create_session_request);
list($session, $status) = $create_session_call->wait();
var_dump($status);


//$list_session_request = new ListSessionsRequest();
//$list_session_request->setDatabase($database);
//$session = $stub->ListSessions($list_session_request);
//list($reply, $status) = $session->wait();
//var_dump($status);
//var_dump($reply->getSessions());
//foreach ($reply->getSessions() as $session) {
//  echo "session:\n";
//  echo "name - ". $session->getName. PHP_EOL;
//}

/*
$sql_cmd = "select FirstName from Singers";
$exec_sql_request = new ExecuteSqlRequest();
$exec_sql_request->setSession($session->getName());
$exec_sql_request->setSql($sql_cmd);
$exec_sql_call = $stub->ExecuteSql($exec_sql_request);
list($exec_sql_reply, $status) = $exec_sql_call->wait();
var_dump($status);
foreach ($exec_sql_reply->getRows() as $row) {
  foreach($row->getValues() as $value) {
    var_dump($value->getStringValue());
  }
}

$sql_cmd = "select FirstName from Singers";
$stream_exec_sql_request = new ExecuteSqlRequest();
$stream_exec_sql_request->setSession($session->getName());
$stream_exec_sql_request->setSql($sql_cmd);
$stream_exec_sql_call = $stub->ExecuteStreamingSql($stream_exec_sql_request);

//PartialResultSet
$features = $stream_exec_sql_call->responses();
foreach ($features as $feature) {
  echo "feature =================================\n";
  foreach ($feature->getValues() as $value) {
      print_r($value);
      var_dump($value->getStringValue());
  }
}
var_dump($stream_exec_sql_call->getStatus());
*/

$_DEFAULT_MAX_CHANNELS_PER_TARGET = 2;

// Test CreateSession Reuse Channel
for ($i=0; $i<$_DEFAULT_MAX_CHANNELS_PER_TARGET; $i++){
  $create_session_request = new CreateSessionRequest();
  $create_session_request->setDatabase($database);
  $create_session_call = $stub->CreateSession($create_session_request);
  list($session, $status) = $create_session_call->wait();
  var_dump($status);
  $delete_session_request = new DeleteSessionRequest();
  $delete_session_request->setName($session->getName());
  list($session, $status) = $stub->DeleteSession($delete_session_request)->wait();
  var_dump($status);
  $result = (count($gcp_channel->getNext()->getChannelRefs()) == $i);
  echo "xxxxxxxxxxxxxxx count: ". count($gcp_channel->getNext()->getChannelRefs()). "\n";
  assert($result, "wrong assertation");
}

//for i in range(_DEFAULT_MAX_CHANNELS_PER_TARGET):
//  session = stub.CreateSession(
//      spanner_pb2.CreateSessionRequest(database=_DATABASE))
//            self.assertIsNotNone(session)
//            self.assertEqual(1, len(self.channel._channel_refs))
//            stub.DeleteSession(
//              spanner_pb2.DeleteSessionRequest(name=session.name))
//        for i in range(_DEFAULT_MAX_CHANNELS_PER_TARGET):
//          session = stub.CreateSession(
//              spanner_pb2.CreateSessionRequest(database=_DATABASE))
//            self.assertIsNotNone(session)
//            self.assertEqual(1, len(self.channel._channel_refs))
//            stub.DeleteSession(
//              spanner_pb2.DeleteSessionRequest(name=session.name))


