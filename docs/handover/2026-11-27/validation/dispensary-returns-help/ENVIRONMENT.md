# Dependency preparation

27Nov: initial own vendor copy failed from shared inode exhaustion (~5,009 free). Removed the incomplete new copy. Confirmed no active processes with cwd in the older published dispensary-queue-search and dispensary-type-filter trees, then removed only their reproducible vendor directories. Source, git history, node dependencies, databases and validation artifacts were retained. Those old trees require vendor restoration before another PHP preview/check.

Freed approximately107k inodes; new returns-help own vendor copy and composer dump-autoload --no-scripts passed. No other team's files touched. Shared storage remains constrained.
