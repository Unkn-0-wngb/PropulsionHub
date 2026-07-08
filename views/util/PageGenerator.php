<?php

class PageGenerator {

    static function generatePages($board, $entryCallback, $maxEntriesShown = PHP_INT_MAX, $entriesPerPage = 40, $wrapper = true) {
        $keys = array_keys($board);
        $numEntries = min($maxEntriesShown, count($keys));
        $pages = ceil($numEntries / $entriesPerPage);
        $page = 1;
        if ($wrapper) { ?><div class="entries pages"><?php } ?>
            <?php while ($page <= $pages): ?>
                <div
                <?php if ($page != 1 || !$wrapper): ?>
                    class="datatable page-entries" style="display:none"
                <?php else: ?>
                    class="datatable page-entries active"
                <?php endif; ?>
                >
                <?php $i = ($entriesPerPage * ($page - 1)) + 1;?>
                    <?php while (($page == $pages) ? ($i <= $numEntries) : ($i <= ($entriesPerPage * $page))): ?>
                        <?php $entryCallback($board, $keys[$i-1], $page, $i) ?>
                    <?php $i++; endwhile; ?>
                </div>
            <?php $page++; endwhile; ?>
        <?php if ($wrapper) { ?></div><?php } ?>
    <?php }

}
