#/bin/bash
nohup php agent.php --listen=case.log >  nohup.log 2>&1 &
nohup php agent.php --indexer > nohup.log 2>&1 &
