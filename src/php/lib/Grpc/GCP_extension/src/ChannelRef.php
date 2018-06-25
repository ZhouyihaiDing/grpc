<?php

namespace Grpc\GCP;

class _ChannelRef
{
  // It has all information except Credentials for creating a Grpc\Channel.
  // It is used in getRealChannel method.
  private $opts;

  private $channel_id;
  private $affinity_ref;
  private $active_stream_ref;
  private $target;

  private $has_deserialized;
  private $real_channel;

  public function __construct($target, $channel_id, $opts, $affinity_ref=0, $active_stream_ref=0)
  {
    $this->target = $target;
    $this->channel_id = $channel_id;
    $this->affinity_ref = $affinity_ref;
    $this->active_stream_ref = $active_stream_ref;
    $this->opts = $opts;
    $this->has_deserialized = new _HasDeserialized();
  }

  public function getRealChannel($credentials) {
    if(!$this->has_deserialized->getData()) {
      return $this->real_channel;
    }
    // 'credentials' in the array $opts will be unset during creating the channel.
    if(!array_key_exists('credentials', $this->opts)){
      $this->opts['credentials'] = $credentials;
    }
    // TODO(ddyihai): Implemente serialize/unserialize handler for the Channel.
    // if not running the script in the PHP-FPM mode, we don't need to save all opts for creating a channel.

    // The only reason for recreating the Grpc\Channel everytime is that Grpc\Channel don't
    // have serialize and deserialize handler. When we fetching the $gcp_channel from the
    // pool, all Grpc\Channel objects are point to an random location which leads to segment
    // fault.
    // Since [target + augments + credentials] will reuse the underline grpc channel in C extension
    // if exists, recreating a PHP object doesn't do too much harm because it only link the
    // \Grpc\Channel to a pointer in underline grpc channel without creating any extra things.
    $real_channel = new \Grpc\Channel($this->target, $this->opts);
    $this->real_channel = $real_channel;
    $this->has_deserialized->setData(0);
    return $real_channel;
  }

  public function getAffinityRef() {return $this->affinity_ref;}
  public function getActiveStreamRef() {return $this->active_stream_ref;}
  public function affinityRefIncr() {$this->affinity_ref += 1;}
  public function affinityRefDecr() {$this->affinity_ref -= 1;}
  public function activeStreamRefIncr() {$this->active_stream_ref += 1;}
  public function activeStreamRefDecr() {$this->active_stream_ref -= 1;}
}

// Since we haven't implement serialze handler for the Grpc\Channel, each time the channel
// fetched from the pool will be invalid.
// _HasDeserialized is used to check whether _ChannelRef is created by deserialization or not.
// If it is, $real_channel is invalid thus we need to recreate it using $opts.
// If not, we can use $real_channel directly instead of creating a new one.
// It is useful to handle 'force_new' channel option.
class _HasDeserialized implements \Serializable {
  private $data;
  public function __construct() {
    $this->data = 1;
  }
  public function serialize() {
    return null;
  }
  public function unserialize($data) {
    $this->data = 1;
  }
  public function setData($data) {
    $this->data = $data;
  }
  public function getData() {
    return $this->data;
  }
}

