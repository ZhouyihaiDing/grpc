
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
  // key => affinity_key, value => channel_ref
  HashTable wrapped_pool;
  char* target;
  int max_size;
  int max_concurrent_streams_low_watermark;
  char* _target;
  grpc_channel_args options;
  wrapped_grpc_channel_credentials* credentials;
  // A map of {method name: affinity config}
  HashTable affinity_by_method;
  gpr_mu _lock;
  // A map of {affinity key: channel_ref_data}.
  HashTable channel_ref_by_affinity_key;
  // A map of managed channel refs.
  HashTable channel_refs;
  HashTable subscribers;
} grpc_gcp_channel;

typedef struct _affinity {
  char* command;
  char* affinity_key;
} affinity;

void init_affinity_by_method_index(grpc_gcp_channel *ext_channel,
                                   HashTable* config);
//  zend_hash_init_ex(&(ext_channel->affinity_by_method), 20, NULL,
//                    EG(persistent_list).pDestructor, 1, 0);
//  zval *data;
//  PHP_GRPC_HASH_FOREACH_VAL_START(&config, data)
//
//  PHP_GRPC_HASH_FOREACH_END()
//        if self._config is not None:
//            for method in self._config.method:
//                # TODO(fengli): supports wildcard in method selector.
//                for name in method.name:
//                    index[name] = method.affinity

//void _get_channel_ref(){}
//
//void _bind(){}
//
//void _bound(){}
//
//void _unbind(){}
//
void grpc_gcp_pre_process();
void grpc_gcp_run_post_process();
void grpc_gcp_channel_init();
void grpc_gcp_bind(grpc_gcp_channel* channels, channel_ref* channel, char* affinity_key);

void grpc_gcp_unbind(grpc_gcp_channel* channels, char* affinity_key);

void grpc_gcp_get_channel_ref(grpc_gcp_channel* channels, char* affinity_key);

#endif /* NET_GRPC_PHP_GRPC_CHANNEL_H_ */
