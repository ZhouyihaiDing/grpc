
#ifndef NET_GRPC_PHP_GRPC_CHANNEL_EXTENSION_H_
#define NET_GRPC_PHP_GRPC_CHANNEL_EXTENSION_H_


#include "php_grpc.h"
#include "channel.h"

extern HashTable grpc_gcp_config;
extern int channel_pool_size;

typedef struct _grpc_extension_channel {
  HashTable wrapped_pool;
  HashTable key;
  HashTable target;
  int _max_size;
  int _max_concurrent_streams_low_watermark;
  char* _target;
  HashTable options;
  char* _credentials;
  // A map of {method name: affinity config}
  HashTable *affinity_by_method;
  gpr_mu _lock;
  // A map of {affinity key: channel_ref_data}.
  HashTable channel_ref_by_affinity_key;
  // A map of managed channel refs.
  HashTable channel_refs;
  HashTable subscribers;
} grpc_extension_channel;

typedef struct _affinity {
  char* command;
  char* affinity_key;
} affinity;

void init_affinity_by_method_index(grpc_extension_channel *ext_channel,
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
void pre_process();
void run_post_process();

#endif /* NET_GRPC_PHP_GRPC_CHANNEL_H_ */
