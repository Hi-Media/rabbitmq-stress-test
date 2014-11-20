#!/usr/bin/env bash

#
# Full reset of specified rabbitmq instance.
# Add a policy providing high availibity for queues whose name start with '^ha-'.
#
# @param $1 Rabbitmq server address to reset. Format: '[user@]IP'.
#

# [user@]IP
SERVER="$1"

ssh -T "$SERVER" <<EOT
    rabbitmqctl stop
    /etc/init.d/rabbitmq-server stop
    rm -rf /var/lib/rabbitmq/mnesia/
    /etc/init.d/rabbitmq-server start
    rabbitmqctl set_policy ha-all "^ha-" '{"ha-mode":"all", "ha-sync-mode":"automatic"}'
EOT
