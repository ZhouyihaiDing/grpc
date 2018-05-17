
#ifndef NET_GRPC_PHP_GRPC_CHANNEL_EXTENSION_H_
#define NET_GRPC_PHP_GRPC_CHANNEL_EXTENSION_H_


#include "php_grpc.h"
#include "channel.h"

typedef struct _grpc_extension_channel {
  HashTable *wrapped_pool;
  HashTable *key;
  HashTable *target;
  int _max_size;
  int _max_concurrent_streams_low_watermark;
  char* _target;
  HashTable *options;
  char* _credentials;
  // A map of {method name: affinity config}
  HashTable *affinity_by_method;
  gpr_mu _lock;
  // A map of {affinity key: channel_ref_data}.
  HashTable *channel_ref_by_affinity_key;
  // A map of managed channel refs.
  HashTable *channel_refs;
  HashTable *subscribers;
} grpc_extension_channel;

void _get_channel_ref(){}

void _bind(){}

void _bound(){}

void _unbind(){}

void pre_process(){}

void post_process(){}

#endif /* NET_GRPC_PHP_GRPC_CHANNEL_H_ */
