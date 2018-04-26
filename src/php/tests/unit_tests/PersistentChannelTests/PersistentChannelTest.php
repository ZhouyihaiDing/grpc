<?php
/*
 *
 * Copyright 2015 gRPC authors.
 *
 * Licensed under the Apache License, Version 2.0 (the "License");
 * you may not use this file except in compliance with the License.
 * You may obtain a copy of the License at
 *
 *     http://www.apache.org/licenses/LICENSE-2.0
 *
 * Unless required by applicable law or agreed to in writing, software
 * distributed under the License is distributed on an "AS IS" BASIS,
 * WITHOUT WARRANTIES OR CONDITIONS OF ANY KIND, either express or implied.
 * See the License for the specific language governing permissions and
 * limitations under the License.
 *
 */

/**
 * @group persistent_list_bound_tests
 */
class PersistentListTest extends PHPUnit_Framework_TestCase
{
  public function setUp()
  {
  }

  public function tearDown()
  {
      $channel_clean_persistent =
          new Grpc\Channel('localhost:50010', []);
      $plist = $channel_clean_persistent->getPersistentList();
      print_r($plist);
      $channel_clean_persistent->cleanPersistentList();
  }

  public function waitUntilNotIdle($channel) {
      for ($i = 0; $i < 10; $i++) {
          $now = Grpc\Timeval::now();
          $deadline = $now->add(new Grpc\Timeval(1000));
          if ($channel->watchConnectivityState(GRPC\CHANNEL_IDLE,
            $deadline)) {
              return true;
          }
      }
      $this->assertTrue(false);
  }

  public function assertConnecting($state) {
      $this->assertTrue($state == GRPC\CHANNEL_CONNECTING ||
      $state == GRPC\CHANNEL_TRANSIENT_FAILURE);
  }

  public function testPersistentChennelCreateOneChannel()
  {
      $this->channel1 = new Grpc\Channel('localhost:1', []);
      $plist = $this->channel1->getPersistentList();
      $this->assertEquals($plist['localhost:1']['target'], 'localhost:1');
      $this->assertArrayHasKey('localhost:1', $plist);
      $this->assertEquals($plist['localhost:1']['ref_count'], 1);
      $this->assertEquals($plist['localhost:1']['connectivity_status'],
                          GRPC\CHANNEL_IDLE);
      $this->channel1->close();
  }

  public function testPersistentChennelStatusChange()
  {
      $this->channel1 = new Grpc\Channel('localhost:1', []);
      $plist = $this->channel1->getPersistentList();
      $this->assertEquals($plist['localhost:1']['connectivity_status'],
                          GRPC\CHANNEL_IDLE);
      $state = $this->channel1->getConnectivityState(true);

      $this->waitUntilNotIdle($this->channel1);
      $plist = $this->channel1->getPersistentList();
      $this->assertConnecting($plist['localhost:1']['connectivity_status']);

      $this->channel1->close();
  }

  public function testPersistentChennelCloseChannel()
  {
      $this->channel1 = new Grpc\Channel('localhost:1', []);
      $plist = $this->channel1->getPersistentList();
      $this->assertEquals($plist['localhost:1']['ref_count'], 1);
      $this->channel1->close();
      $plist = $this->channel1->getPersistentList();
      $this->assertArrayHasKey('localhost:1', $plist);
  }

  public function testPersistentChannelSameHost()
  {
      $this->channel1 = new Grpc\Channel('localhost:1', []);
      $this->channel2 = new Grpc\Channel('localhost:1', []);
      //ref_count should be 2
      $plist = $this->channel1->getPersistentList();
      $this->assertArrayHasKey('localhost:1', $plist);
      $this->assertEquals($plist['localhost:1']['ref_count'], 2);
      $this->channel1->close();
      $this->channel2->close();
  }

  public function testPersistentChannelDifferentHost()
  {
      $this->channel1 = new Grpc\Channel('localhost:1', []);
      $this->channel2 = new Grpc\Channel('localhost:2', []);
      $plist = $this->channel1->getPersistentList();
      $this->assertArrayHasKey('localhost:1', $plist);
      $this->assertArrayHasKey('localhost:2', $plist);
      $this->assertEquals($plist['localhost:1']['ref_count'], 1);
      $this->assertEquals($plist['localhost:2']['ref_count'], 1);
      $this->channel1->close();
      $this->channel2->close();
  }


  /**
   * @expectedException RuntimeException
   * @expectedExceptionMessage startBatch Error. Channel is closed
   */
  public function testPersistentChannelSharedChannelClose()
  {
      // same underlying channel
      $this->channel1 = new Grpc\Channel('localhost:10010', []);
      $this->channel2 = new Grpc\Channel('localhost:10010', []);
      $this->server = new Grpc\Server([]);
      $this->port = $this->server->addHttp2Port('localhost:10010');

      // channel2 can still be use
      $state = $this->channel2->getConnectivityState();
      $this->assertEquals(GRPC\CHANNEL_IDLE, $state);

      $call1 = new Grpc\Call($this->channel1,
        '/foo',
        Grpc\Timeval::infFuture());
      $call2 = new Grpc\Call($this->channel2,
        '/foo',
        Grpc\Timeval::infFuture());
      $call3 = new Grpc\Call($this->channel1,
        '/foo',
        Grpc\Timeval::infFuture());
      $call4 = new Grpc\Call($this->channel2,
        '/foo',
        Grpc\Timeval::infFuture());
      $batch = [
        Grpc\OP_SEND_INITIAL_METADATA => [],
      ];

      $result = $call1->startBatch($batch);
      $this->assertTrue($result->send_metadata);
      $result = $call2->startBatch($batch);
      $this->assertTrue($result->send_metadata);

      $this->channel1->close();
      // After closing channel1, channel2 can still be use
      $result = $call4->startBatch($batch);
      $this->assertTrue($result->send_metadata);
      // channel 1 is closed
      $result = $call3->startBatch($batch);
  }

  public function testPersistentChannelDefaultUpperBound()
  {
    // the default size is 3
    $this->channel1 = new Grpc\Channel('localhost:1', []);
    $plist = $this->channel1->getPersistentList();
    print_r($plist);
    $this->assertEquals($plist['persistent_list_max_size'], 20);
    $this->channel1->close();
  }

  public function testPersistentChannelChangeUpperBound()
  {
    // the default size is 3
    $this->channel1 = new Grpc\Channel('localhost:1', [
      "persistent_list_max_size" => 15,
    ]);
    $plist = $this->channel1->getPersistentList();
    $this->assertEquals($plist['persistent_list_max_size'], 15);
    $this->channel2 = new Grpc\Channel('localhost:2', [
      "persistent_list_max_size" => 3,
    ]);
    $plist = $this->channel1->getPersistentList();
    $this->assertEquals($plist['persistent_list_max_size'], 3);
    $this->channel1->close();
    $this->channel2->close();
  }

  public function testPersistentChannelOutbound1()
  {
    $this->channel1 = new Grpc\Channel('localhost:1', [
      "plist_bound" => 3,
    ]);
    $plist = $this->channel1->getPersistentList();
    $this->channel2 = new Grpc\Channel('localhost:2', []);
    $plist = $this->channel1->getPersistentList();
    $this->channel3 = new Grpc\Channel('localhost:3', []);
    $this->channel4 = new Grpc\Channel('localhost:4', []);
    $plist = $this->channel1->getPersistentList();
    // Only channel 1\2\3 should be inside persistent list
    $this->assertArrayHasKey('localhost:1', $plist);
    $this->assertArrayHasKey('localhost:2', $plist);
    $this->assertArrayHasKey('localhost:3', $plist);
    $this->assertArrayNotHasKey('localhost:4', $plist);

    $this->channel1->close();
    $this->channel2->close();
    $this->channel3->close();
    $this->channel4->close();
  }

  public function testPersistentChannelOutbound2()
  {
    $this->channel1 = new Grpc\Channel('localhost:1', [
      "plist_bound" => 3,
    ]);
    $this->channel1->close();
    $plist = $this->channel1->getPersistentList();
    $this->channel2 = new Grpc\Channel('localhost:2', []);
    $plist = $this->channel1->getPersistentList();
    $this->channel3 = new Grpc\Channel('localhost:3', []);
    $this->channel4 = new Grpc\Channel('localhost:4', []);
    $plist = $this->channel2->getPersistentList();
    // Only channel 2\3\4 should be inside persistent list
    $this->assertArrayHasKey('localhost:2', $plist);
    $this->assertArrayHasKey('localhost:3', $plist);
    $this->assertArrayHasKey('localhost:4', $plist);
    $this->assertArrayNotHasKey('localhost:1', $plist);

    $this->channel2->close();
    $this->channel3->close();
    $this->channel4->close();
  }

  public function testPersistentChannelOutbound3()
  {
    $this->channel1 = new Grpc\Channel('localhost:1', [
      "plist_bound" => 3,
    ]);
    $this->channel1_share = new Grpc\Channel('localhost:1', []);
    $this->channel1->close();
    $plist = $this->channel1->getPersistentList();
    $this->channel2 = new Grpc\Channel('localhost:2', []);
    $plist = $this->channel1->getPersistentList();
    $this->channel3 = new Grpc\Channel('localhost:3', []);
    $this->channel4 = new Grpc\Channel('localhost:4', []);
    $plist = $this->channel2->getPersistentList();
    // Only channel 1\2\3 should be inside persistent list
    // because channel1 still has ref_count which could not be deleted
    $this->assertArrayHasKey('localhost:1', $plist);
    $this->assertArrayHasKey('localhost:2', $plist);
    $this->assertArrayHasKey('localhost:3', $plist);
    $this->assertArrayNotHasKey('localhost:4', $plist);

    $this->channel2->close();
    $this->channel3->close();
    $this->channel4->close();
  }

  public function testPersistentChannelOutbound4()
  {
    $this->channel1 = new Grpc\Channel('localhost:1', [
      "plist_bound" => 3,
    ]);
    $this->channel1_share = new Grpc\Channel('localhost:1', []);
    $this->channel1->close();
    $this->channel1_share->close();
    $plist = $this->channel1->getPersistentList();
    $this->channel2 = new Grpc\Channel('localhost:2', []);
    $plist = $this->channel1->getPersistentList();
    $this->channel3 = new Grpc\Channel('localhost:3', []);
    $this->channel4 = new Grpc\Channel('localhost:4', []);
    $plist = $this->channel2->getPersistentList();
    // Only channel 1\2\3 should be inside persistent list
    $this->assertArrayHasKey('localhost:2', $plist);
    $this->assertArrayHasKey('localhost:3', $plist);
    $this->assertArrayHasKey('localhost:4', $plist);
    $this->assertArrayNotHasKey('localhost:1', $plist);

    $this->channel2->close();
    $this->channel3->close();
    $this->channel4->close();
  }

  public function testPersistentChannelOutbound5()
  {
    $this->channel1 = new Grpc\Channel('localhost:1', [
      "plist_bound" => 3,
    ]);
    $this->channel1_share = new Grpc\Channel('localhost:1', []);
    $this->channel1->close();
    $this->channel1_share->close();
    $plist = $this->channel1->getPersistentList();
    $this->channel2 = new Grpc\Channel('localhost:2', []);
    $plist = $this->channel1->getPersistentList();
    $this->channel3 = new Grpc\Channel('localhost:3', []);
    $this->channel4 = new Grpc\Channel('localhost:4', []);
    $plist = $this->channel2->getPersistentList();
    // Only channel 1\2\3 should be inside persistent list
    $this->assertArrayHasKey('localhost:2', $plist);
    $this->assertArrayHasKey('localhost:3', $plist);
    $this->assertArrayHasKey('localhost:4', $plist);
    $this->assertArrayNotHasKey('localhost:1', $plist);

    $this->channel2->close();
    $this->channel3->close();
    $this->channel4->close();
  }
}
