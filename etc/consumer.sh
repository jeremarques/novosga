#!/usr/bin/env sh

echo "*** NovoSGA consumer ***"
echo "Setting up Messenger transports: $1"
php bin/console messenger:setup-transports

while true
do
    echo "Starting Messenger Consumer: $1"
    php bin/console messenger:consume $1 -vv --time-limit=3600
    echo "Consumer stopped (time-limit achived)"
done

