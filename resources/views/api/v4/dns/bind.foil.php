;;
;; This file contains DNS reverse lookup records for customers VLAN interfaces
;;
;; WARNING: this file is automatically generated using the
;; api/v4/dns/arpa API call to IXP Manager. Any local changes made to
;; this script will be lost.
;;
;; VLAN id: <?= $t->vlan->id ?>; tag: <?= $t->vlan->number ?>; name: <?= $t->vlan->name ?>.
;;
;; Generated: <?= now()->format( 'Y-m-d H:i:s' ) . "\n" ?>
;;

<?php foreach( $t->arpa as $a ): ?>
<?= trim($a['arpa']) ?>    IN      PTR     <?= trim($a['hostname']) ?>.
<?php endforeach; ?>

;; END
