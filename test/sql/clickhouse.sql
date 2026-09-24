CREATE TABLE sites (
	id UInt32,
	name String,
	created_at DateTime
) ENGINE = MergeTree
ORDER BY id;

-- UInt128 key: the adapter generates a UUIDv7 for it. Lightweight UPDATE needs
-- the block number and offset columns.
CREATE TABLE page_views (
	id UInt128,
	site_id UInt32,
	url String,
	referrer Nullable(String),
	duration Float64 DEFAULT 0,
	tags Array(String),
	payload JSON,
	viewed_on Date,
	created_at DateTime,
	updated_at DateTime
) ENGINE = MergeTree
ORDER BY (site_id, id)
SETTINGS enable_block_number_column = 1, enable_block_offset_column = 1;

-- No primary key at all, as in most analytics tables: the sorting key columns
-- are not unique and must not be taken as one.
CREATE TABLE daily_metrics (
	site_id UInt32,
	day Date,
	metric LowCardinality(String),
	value Float64 DEFAULT 1.5,
	note String DEFAULT 'it''s \\ fine',
	computed UInt32 MATERIALIZED site_id * 2,
	aliased UInt32 ALIAS site_id + 1
) ENGINE = MergeTree
ORDER BY (site_id, day, metric);

-- UUID key: generated as UUID text
CREATE TABLE sessions (
	id UUID,
	site_id UInt32
) ENGINE = MergeTree
ORDER BY site_id;

CREATE TABLE typed (
	u8 UInt8,
	i32 Int32,
	u64 UInt64,
	i64 Int64,
	u128 UInt128,
	f64 Float64,
	dec Decimal(12, 3),
	b Bool,
	s String,
	fs FixedString(3),
	lc LowCardinality(String),
	ns Nullable(String),
	e Enum8('a' = 1, 'b' = 2),
	d Date,
	dt DateTime,
	dt64 DateTime64(3),
	j JSON,
	arr Array(UInt32),
	m Map(String, UInt32),
	uuid UUID
) ENGINE = MergeTree
ORDER BY tuple();
