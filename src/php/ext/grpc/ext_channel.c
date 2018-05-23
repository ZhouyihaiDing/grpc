#include "ext_channel.h"

// Global variables for gcp extension
extern HashTable grpc_gcp_config;
extern int channel_pool_size;
extern grpc_gcp_channel* ext_channel;

void pre_process(){}
//
void run_post_process(){}

void helper_print_channel_ref(channel_ref* channel) {
  php_printf("affinity_ref: %d\n", channel->affinity_ref);
  php_printf("active_stream_ref: %d\n", channel->active_stream_ref);
}

HashTable a;
void grpc_gcp_channel_init(
    grpc_gcp_channel* channels,
    char *target,
    zval* args,
    wrapped_grpc_channel_credentials *creds) {
    php_printf("grpc_gcp_channel_init\n");
  channels->max_size = 1;
  channels->max_concurrent_streams_low_watermark = 1;
  channels->target = target;
  channels->options = args;
  channels->credentials = creds;
  channels->affinity_by_method = grpc_gcp_config;
  zend_hash_init_ex(&channels->wrapped_pool, 20, NULL,
                    EG(persistent_list).pDestructor, 1, 0);
  zend_hash_init_ex(&channels->channel_ref_by_affinity_key, 20, NULL,
                 EG(persistent_list).pDestructor, 1, 0);
  zend_hash_init_ex(&channels->channel_refs, 20, NULL,
                    EG(persistent_list).pDestructor, 1, 0);
  zend_hash_init_ex(&channels->subscribers, 20, NULL,
                    EG(persistent_list).pDestructor, 1, 0);
   zend_hash_init_ex(&a, 20, NULL,
                      EG(persistent_list).pDestructor, 1, 0);
  gpr_mu_init(&channels->mu);
  php_printf("----grpc_gcp_channel_init ends----\n");
}

extern HashTable grpc_target_upper_bound_map;

channel_ref* grpc_gcp_bind(grpc_gcp_channel* channels, channel_ref* channel, char* affinity_key) {
  php_printf("grpc_gcp_bind\n");
  channel_ref* cur_channel = NULL;
  gpr_mu_lock(&channels->mu);
  php_grpc_zend_resource *rsrc_find;
  if ((PHP_GRPC_PERSISTENT_LIST_FIND(&channels->channel_ref_by_affinity_key,
                                     affinity_key, strlen(affinity_key), rsrc_find))) {
    php_printf("find affinity_key: %s\n", affinity_key);
    cur_channel = (channel_ref*) rsrc_find->ptr;
  } else {
    php_printf("not find affinity_key: %s\n", affinity_key);
    php_grpc_zend_resource new_rsrc;
    new_rsrc.ptr = channel;
    PHP_GRPC_PERSISTENT_LIST_UPDATE(&channels->channel_ref_by_affinity_key,
                                    affinity_key, strlen(affinity_key), (void *)&new_rsrc);
    cur_channel = channel;
    helper_print_channel_ref(new_rsrc.ptr);
  }
  cur_channel->active_stream_ref += 1;
  gpr_mu_unlock(&channels->mu);
  php_printf("----grpc_gcp_bind ends----\n");
  return cur_channel;
}

channel_ref* grpc_gcp_unbind(grpc_gcp_channel* channels, char* affinity_key) {
  php_printf("grpc_gcp_unbind\n");
  gpr_mu_lock(&channels->mu);
  php_grpc_zend_resource *rsrc_find;
  channel_ref* cur_channel = NULL;
  if ((PHP_GRPC_PERSISTENT_LIST_FIND(&channels->channel_ref_by_affinity_key,
                                     affinity_key, strlen(affinity_key), rsrc_find))) {
    php_printf("find affinity_key: %s\n", affinity_key);
    cur_channel = (channel_ref*) rsrc_find->ptr;
    cur_channel->active_stream_ref -= 1;
  }
  gpr_mu_unlock(&channels->mu);
  php_printf("----grpc_gcp_unbind ends----\n");
  return cur_channel;
}

channel_ref* grpc_gcp_get_channel_ref(grpc_gcp_channel* channels, char* affinity_key) {
  php_printf("grpc_gcp_get_channel_ref\n");
  gpr_mu_lock(&channels->mu);
  channel_ref* cur_channel = NULL;
  if (strcmp(affinity_key, "")) {
    // Has affinity_key
    php_grpc_zend_resource *rsrc_find;
    if ((PHP_GRPC_PERSISTENT_LIST_FIND(&channels->channel_ref_by_affinity_key,
                                         affinity_key, strlen(affinity_key), rsrc_find))) {
      cur_channel = (channel_ref*) rsrc_find->ptr;
      php_printf("find affinity_key: %s\n", affinity_key);
      gpr_mu_unlock(&channels->mu);
    } else {
      gpr_mu_unlock(&channels->mu);
      cur_channel = grpc_gcp_get_channel_ref(channels, "");
    }
    return cur_channel;
  }
  long num_ref_channels = (long)PHP_GRPC_PERSISTENT_LIST_SIZE(
                           &channels->channel_ref_by_affinity_key);
  // TODO: sort to get the least traffic channel.
  // zend_hash_sort(&channels->channel_ref_by_affinity_key, )
  // zend_hash_index_find(&channels->channel_ref_by_affinity_key, )
  zval *data;
  PHP_GRPC_HASH_FOREACH_VAL_START(&channels->channel_ref_by_affinity_key, data)
    php_grpc_zend_resource *rsrc  = (php_grpc_zend_resource*) PHP_GRPC_HASH_VALPTR_TO_VAL(data)
    cur_channel = rsrc->ptr;
    helper_print_channel_ref(cur_channel);
    if (cur_channel->active_stream_ref < 0) {
      php_printf("!!! find channel has resources active_stream_ref: %d\n",
        cur_channel->active_stream_ref);
      gpr_mu_unlock(&channels->mu);
      return cur_channel;
    }
    break;
  PHP_GRPC_HASH_FOREACH_END()

  if (num_ref_channels < channel_pool_size) {
    php_printf("!!! find channel has resources channel_pool_size\n");
    zval* channel_id = malloc(sizeof(zval));
    ZVAL_LONG(channel_id, 10);
    if (channel_id) {}
    #if PHP_MAJOR_VERSION < 7
    zend_hash_update(channels->options, "grpc_gcp.client_channel.id",
                     sizeof("grpc_gcp.client_channel.id") + 1, &channel_id,
                     sizeof(zval *), NULL);
    #else
    zend_string *name = zend_string_init("grpc_gcp.client_channel.id",
                                         sizeof("grpc_gcp.client_channel.id"), 1);
    if (name) {}
    zend_hash_update(Z_ARRVAL_P(channels->options), name, channel_id);
    #endif
    grpc_channel_args args;
    if (php_grpc_read_args_array(channels->options, &args TSRMLS_CC) == FAILURE) {
      efree(args.args);
      // TODO: throw exception
    }
    php_printf("credentials hash: %s\n", channels->credentials->hashstr);
    php_printf("target: %s\n", channels->target);
    grpc_channel* channel = grpc_secure_channel_create(channels->credentials->wrapped,
                                         channels->target, &args, NULL);
    cur_channel = malloc(sizeof(channel_ref));
    cur_channel->channel = channel;
  }
  gpr_mu_unlock(&channels->mu);
  php_printf("----grpc_gcp_get_channel_ref ends----\n");
  return cur_channel;
}

