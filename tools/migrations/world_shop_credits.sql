-- Ledger of shop credits, on the game side.
--
-- Without this a `pending` order is ambiguous: if the process died between the
-- gold UPDATE and the order being marked fulfilled, there is no way to tell
-- whether the player was paid. Guessing loses either the player's coins or the
-- server's gold.
--
-- Writing this row in the same transaction as the UPDATE makes the credit
-- idempotent and the answer knowable: if the reference is here, the gold landed.
--
-- Idempotent, and applied on every run of tools/install.sh so that worlds
-- installed before this existed get it too.

CREATE TABLE IF NOT EXISTS `shop_gold_credits`
(
    `id`        INT(11) UNSIGNED NOT NULL AUTO_INCREMENT,
    `reference` VARCHAR(64)      NOT NULL,
    `uid`       INT(11) UNSIGNED NOT NULL,
    `gold`      INT(11)          NOT NULL,
    `time`      INT(11) UNSIGNED NOT NULL,
    PRIMARY KEY (`id`),
    UNIQUE KEY `reference` (`reference`),
    KEY `uid` (`uid`)
) ENGINE = InnoDB
  DEFAULT CHARSET = utf8mb4;
