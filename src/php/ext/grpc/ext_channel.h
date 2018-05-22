
#ifndef NET_GRPC_PHP_GRPC_CHANNEL_EXTENSION_H_
#define NET_GRPC_PHP_GRPC_CHANNEL_EXTENSION_H_


#include "php_grpc.h"
#include "channel.h"
#include "channel_credentials.h"

typedef struct _channel_ref {
  grpc_channel* channel;
  int channel_id;
  int affinity_ref;
  int active_stream_ref;
} channel_ref;


typedef struct _grpc_gcp_channel {
  int max_size;
  int max_concurrent_streams_low_watermark;
  char* target;
  zval* options;
  wrapped_grpc_channel_credentials* credentials;
  gpr_mu mu;

  // key => affinity_key, value => channel_ref
  HashTable wrapped_pool;
  // A map of {method name: affinity config}
  HashTable affinity_by_method;
  // A map of {affinity key: channel_ref_data}.
  HashTable channel_ref_by_affinity_key;
  // A map of managed channel refs.
  // key => channel_ref,
  HashTable channel_refs;
  HashTable subscribers;
} grpc_gcp_channel;

typedef struct _affinity {
  char* command;
  char* affinity_key;
} affinity;

void init_affinity_by_method_index(grpc_gcp_channel *ext_channel,
                                   HashTable* config);

//void _get_channel_ref(){}

wrapped_grpc_channel* grpc_gcp_pre_process(char* method);

void grpc_gcp_run_post_process();
void grpc_gcp_channel_init();

channel_ref* grpc_gcp_bind(grpc_gcp_channel* channels, channel_ref* channel, char* affinity_key);

channel_ref* grpc_gcp_unbind(grpc_gcp_channel* channels, char* affinity_key);

channel_ref* grpc_gcp_get_channel_ref(grpc_gcp_channel* channels, char* affinity_key);

#endif /* NET_GRPC_PHP_GRPC_CHANNEL_H_ */
