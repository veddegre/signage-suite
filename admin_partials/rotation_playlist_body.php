          <div class="help" style="margin-bottom:8px">Drag <strong>⋮⋮</strong> to reorder. Boards (weather, RSS, …): set <strong>Dwell</strong> on each card header. Deployed slides: edit <strong>Sec</strong> on <a href="?board=slides">Custom Slides</a>, then Save &amp; Deploy. Expand a card for hour windows (multiple ranges OK, e.g. commute times), optional <strong>weight per window</strong>, and a page-level <strong title="<?= h(rotation_weight_tooltip()) ?>">Weight</strong> fallback. Save — kiosks pick up changes within ~30s.</div>
          <?php if (!empty($screenSettings['weighted'])): ?>
          <div class="help" style="margin-bottom:8px"><strong>Weighted</strong> is on for this display — each page's <strong title="<?= h(rotation_weight_tooltip()) ?>">Weight</strong> (1–20, default 1) is how many slots it gets in each shuffled cycle. Higher weight = more airtime, but every board still plays at least once per cycle.</div>
          <?php endif; ?>

          <?php
          $playsNow = rotation_schedule_snapshot($screenKey);
          $playsStatusLabel = [
              'playing' => 'Now',
              'skipped' => 'Skip',
              'scheduled' => 'Later',
              'filtered' => 'Hidden',
          ];
          ?>
          <div class="rotation-plays-now" style="margin:12px 0;padding:14px 16px;border:1px solid var(--hairline);border-radius:10px;background:var(--harbor)">
            <div style="display:flex;flex-wrap:wrap;gap:8px 14px;align-items:center;margin-bottom:10px">
              <strong>Plays now</strong>
              <span class="help" style="margin:0"><?= h((string)$playsNow['now']) ?> · <?= h((string)$playsNow['weekday']) ?> · <?= h((string)$playsNow['timezone']) ?></span>
              <?php if (!empty($playsNow['blank'])): ?><span class="pill warn">Blank hours</span><?php endif; ?>
              <?php if (!empty($playsNow['calendar_override'])): ?><span class="pill ok">Calendar override</span><?php endif; ?>
              <span class="pill ok"><?= (int)$playsNow['eligible_count'] ?> eligible</span>
            </div>
            <?php if (($playsNow['rows'] ?? []) === []): ?>
            <div class="help" style="margin:0">No playlist entries on this display.</div>
            <?php else: ?>
            <div class="rotation-plays-now-list" style="display:flex;flex-direction:column;gap:6px;max-height:220px;overflow:auto">
              <?php foreach ($playsNow['rows'] as $snapRow):
                $st = (string)($snapRow['status'] ?? '');
                $pillClass = match ($st) {
                    'playing' => 'ok',
                    'scheduled' => 'warn',
                    default => '',
                };
              ?>
              <div style="display:flex;gap:10px;align-items:baseline;font-size:13px">
                <span class="pill <?= h($pillClass) ?>" style="min-width:72px;justify-content:center"><?= h($playsStatusLabel[$st] ?? $st) ?></span>
                <span><strong><?= h((string)($snapRow['label'] ?? '')) ?></strong><?php if (($snapRow['schedule'] ?? '') !== ''): ?>
                  <span class="help" style="margin:0"> · <?= h((string)$snapRow['schedule']) ?></span><?php endif; ?>
                  <?php if ($st === 'playing' && !empty($playsNow['weighted'])): ?>
                  <span class="help" style="margin:0"> · weight <?= h((string)($snapRow['weight_label'] ?? (string)(int)($snapRow['weight'] ?? 1))) ?></span>
                  <?php if (isset($snapRow['weight_pct'])): ?>
                  <span class="help" style="margin:0"> · ~<?= h((string)$snapRow['weight_pct']) ?>% picks</span>
                  <?php endif; ?>
                  <?php endif; ?></span>
              </div>
              <?php endforeach; ?>
            </div>
            <?php endif; ?>
          </div>

          <div class="rotation-screen-tools">
            <a class="secondary" style="padding:6px 12px;text-decoration:none;font-size:13px"
               href="<?= h(rotation_screen_preview_url($screenKey)) ?>" target="_blank" rel="noopener">Preview ↗</a>
            <span class="help" style="margin:0"><code><?= h(rotation_screen_kiosk_url($screenKey)) ?></code></span>
            <?php if ($screenKey !== 'main'): ?>
            <button type="button" class="secondary" onclick="document.getElementById('rotationTargetScreen').value='<?= h($deckId) ?>'; copyRotationFromMain('<?= h($deckId) ?>')">Copy from main</button>
            <?php endif; ?>
          </div>

          <?php if (admin_is_super()):
            $sharedEditors = rotation_screen_shared_editors($screenKey);
            $ownerUid = users_screen_assignments()[$screenKey] ?? '';
          ?>
          <div class="rotation-shared-editors" style="margin:12px 0;padding:14px 16px;border:1px solid var(--hairline);border-radius:10px;background:var(--harbor)">
            <div class="field" style="margin-bottom:12px;max-width:280px">
              <label class="l">Primary owner</label>
              <select name="SCREEN_OWNER[<?= h($screenKey) ?>]" title="Operator who owns this display (playlist, deploy, kiosk login)">
                <option value="">— Unassigned —</option>
                <?php foreach ($rotationOperatorOptions as $op): ?>
                <option value="<?= h((string)$op['id']) ?>" <?= $ownerUid === (string)$op['id'] ? 'selected' : '' ?>><?= h((string)$op['username']) ?></option>
                <?php endforeach; ?>
              </select>
              <div class="help" style="margin-top:6px">Saved with rotation. Picking a <strong>super admin</strong> demotes them to operator (another super admin must remain). Same setting as <a href="?board=users">Users</a>.</div>
            </div>
            <div class="help" style="margin-bottom:10px"><strong>Shared editing</strong> — operators who may manage the <strong>full display</strong> (playlist, display options, hero strip, deploy targets) without being the primary owner.</div>
            <div class="field-grid" style="grid-template-columns:repeat(auto-fill,minmax(180px,1fr))">
              <?php foreach (users_by_id() as $ou):
                if (!is_array($ou) || users_normalize_role((string)($ou['role'] ?? '')) !== 'operator') {
                    continue;
                }
                $uid = (string)($ou['id'] ?? '');
                if ($uid === '') {
                    continue;
                }
                if ($ownerUid === $uid) {
                    continue;
                }
              ?>
              <label class="check"><input type="checkbox" name="SCREEN_EDITORS[<?= h($screenKey) ?>][]" value="<?= h($uid) ?>"
                <?= in_array($uid, $sharedEditors, true) ? 'checked' : '' ?>> <?= h((string)($ou['username'] ?? '')) ?></label>
              <?php endforeach; ?>
            </div>
          </div>
          <?php endif; ?>

          <?php if ($effectivePages !== [] && $mirrorsMain): ?>
          <div class="rotation-effective-list mirror-note">Mirrors main — <?= (int)$activeEffective ?> page<?= $activeEffective === 1 ? '' : 's' ?> on wall</div>
          <?php endif; ?>
          <?php if ($mirrorsMain && $deckTargetedForScreen > 0 && $slideEntryCount === 0): ?>
          <div class="rotation-effective-list mirror-note" style="border-color:var(--warn);color:var(--warn)">
            <?= (int)$deckTargetedForScreen ?> custom slide<?= $deckTargetedForScreen === 1 ? '' : 's' ?> target this display but are not on its playlist yet.
            <a href="?board=slides&amp;tab=deploy">Deploy from Custom Slides</a>, check <code><?= h($screenKey) ?></code>, then <strong>Deploy now</strong>.
          </div>
          <?php endif; ?>

          <div class="rotation-playlist" id="<?= h($deckId) ?>" data-field="<?= h($fieldKey) ?>">
            <?php if ($storedRows === [] && $pageRows === []): ?>
            <div class="rotation-playlist-empty" data-rotation-empty>
              <span>No pages yet.</span>
              <button type="button" class="secondary" onclick="loadRotationStarter('<?= h($deckId) ?>')">Load starter playlist</button>
              <span class="help" style="margin:0">or add boards in <strong>Add boards</strong> above</span>
            </div>
            <?php endif; ?>
            <?php $pri = 0;
            $playlistSegments = rotation_playlist_segments($pageRows);
            foreach ($playlistSegments as $segment):
              if (($segment['type'] ?? '') === 'slides'):
                $slideItems = $segment['items'] ?? [];
                $slideCount = count($slideItems);
                $legacyOnly = $slideCount === 1 && rotation_is_legacy_slides_url((string)($slideItems[0]['url'] ?? ''));
            ?>
            <details class="rotation-slides-group">
              <summary>
                <span class="drag-handle rotation-slides-group-handle" title="Drag slide block" draggable="true">⋮⋮</span>
                <strong><?= $legacyOnly ? 'Custom slides (legacy)' : 'Custom slides (' . (int)$slideCount . ')' ?></strong>
                <span class="help" style="margin:0">Managed from Custom Slides — deploy or save deck to sync dwell &amp; order</span>
                <a class="secondary" style="padding:4px 10px;text-decoration:none;font-size:12px" href="?board=slides">Edit deck ↗</a>
              </summary>
              <div class="rotation-slides-group-body">
                <?php foreach ($slideItems as $item):
                  $prow = $item['row'];
                  $purl = (string)$item['url'];
                  if ($legacyOnly):
                ?>
                <div class="rotation-card rotation-card-legacy" data-rotation-card>
                  <div class="rotation-card-head">
                    <div class="rotation-card-title">
                      <strong>Legacy single entry</strong>
                      <code>slides.php</code>
                    </div>
                  </div>
                  <div class="rotation-card-meta">
                    <span class="pill warn">Deploy from Custom Slides to split into per-slide entries</span>
                  </div>
                  <input type="hidden" name="<?= h($fieldKey) ?>[<?= (int)$pri ?>][url]" value="<?= h($purl) ?>">
                  <input type="hidden" name="<?= h($fieldKey) ?>[<?= (int)$pri ?>][dwell]" value="<?= h((string)($prow['dwell'] ?? '')) ?>">
                  <?php if (!empty($prow['from'])): ?><input type="hidden" name="<?= h($fieldKey) ?>[<?= (int)$pri ?>][from]" value="<?= h((string)$prow['from']) ?>"><?php endif; ?>
                  <?php if (!empty($prow['to'])): ?><input type="hidden" name="<?= h($fieldKey) ?>[<?= (int)$pri ?>][to]" value="<?= h((string)$prow['to']) ?>"><?php endif; ?>
                  <?php if (!empty($prow['off'])): ?><input type="hidden" name="<?= h($fieldKey) ?>[<?= (int)$pri ?>][off]" value="1"><?php endif; ?>
                </div>
                <?php $pri++; continue; endif;
                  $slideFile = slide_rotation_parse_file($purl);
                  $slideMeta = $slideFile !== null ? slide_deck_by_file($slideFile, $rawConf['slides.SLIDES'] ?? null) : null;
                  $slideLabel = rotation_page_label($purl);
                ?>
                <div class="rotation-card rotation-card-slide" data-rotation-card>
                  <div class="rotation-card-head">
                    <span class="drag-handle" title="Drag to reorder" draggable="true">⋮⋮</span>
                    <div class="rotation-card-title">
                      <strong data-rotation-label><?= h($slideLabel) ?></strong>
                      <code data-rotation-url-display><?= h($purl) ?></code>
                    </div>
                  </div>
                  <div class="rotation-card-grid rotation-card-grid-compact">
                    <div style="grid-column:1 / -1">
                      <label class="mini">URL</label>
                      <input type="text" name="<?= h($fieldKey) ?>[<?= (int)$pri ?>][url]" value="<?= h($purl) ?>" data-rotation-url readonly>
                    </div>
                    <div>
                      <label class="mini">From hr</label>
                      <input type="text" name="<?= h($fieldKey) ?>[<?= (int)$pri ?>][from]" value="<?= h((string)($prow['from'] ?? '')) ?>" placeholder="0-23">
                    </div>
                <div>
                  <label class="mini">To hr</label>
                  <input type="text" name="<?= h($fieldKey) ?>[<?= (int)$pri ?>][to]" value="<?= h((string)($prow['to'] ?? '')) ?>" placeholder="0-23">
                </div>
                <div>
                  <label class="mini" title="<?= h(rotation_weight_tooltip()) ?>">Weight</label>
                  <input type="text" name="<?= h($fieldKey) ?>[<?= (int)$pri ?>][weight]" value="<?= h((string)($prow['weight'] ?? '')) ?>" placeholder="1" title="<?= h(rotation_weight_tooltip()) ?>">
                </div>
                <?php
                $slideDwellShow = trim((string)($prow['dwell'] ?? ''));
                $slideDwellLabel = $slideDwellShow !== '' ? (int)$slideDwellShow : (int)slides_default_dwell();
                ?>
                <div style="grid-column:1 / -1">
                  <span class="help" style="margin:0"><strong><?= (int)$slideDwellLabel ?>s</strong> per slide — edit <strong>Sec</strong> on <a href="?board=slides">Custom Slides</a>, then Save &amp; Deploy.</span>
                  <input type="hidden" name="<?= h($fieldKey) ?>[<?= (int)$pri ?>][dwell]" value="<?= h((string)($prow['dwell'] ?? '')) ?>">
                </div>
              </div>
              <div class="rotation-card-meta">
                <label class="check" style="margin:0"><input type="checkbox" name="<?= h($fieldKey) ?>[<?= (int)$pri ?>][off]" <?= !empty($prow['off']) ? 'checked' : '' ?>> Skip this slide</label>
                    <?php
                    $slideProof = signage_presence_page_proof_label($screenPresence, $purl);
                    if ($slideProof !== ''): ?>
                      <span class="pill play-proof" title="Proof of play (kiosk reported on screen)"><?= h($slideProof) ?></span>
                    <?php endif; ?>
                    <?php if (is_array($slideMeta)): ?>
                      <span><?= h(slide_schedule_summary($slideMeta)) ?></span>
                    <?php endif; ?>
                    <div class="rotation-card-actions">
                      <a class="secondary" style="padding:6px 12px;text-decoration:none;font-size:13px" href="<?= h(signage_rotation_page_preview_url($purl, $screenKey)) ?>" target="_blank" rel="noopener" data-rotation-preview>Preview</a>
                    </div>
                  </div>
                </div>
                <?php $pri++; endforeach; ?>
              </div>
            </details>
            <?php continue; endif;
              if (($segment['type'] ?? '') === 'photos'):
                $photoItems = $segment['items'] ?? [];
                $photoCount = count($photoItems);
                $legacyRotator = $photoCount === 1 && rotation_is_legacy_rotator_url((string)($photoItems[0]['url'] ?? ''));
            ?>
            <details class="rotation-slides-group">
              <summary>
                <span class="drag-handle rotation-slides-group-handle" title="Drag photo block" draggable="true">⋮⋮</span>
                <strong><?= $legacyRotator ? 'Photo rotator (legacy)' : 'Photos (' . (int)$photoCount . ')' ?></strong>
                <span class="help" style="margin:0">Managed from Photo Rotator — deploy or save deck to sync dwell &amp; order</span>
                <a class="secondary" style="padding:4px 10px;text-decoration:none;font-size:12px" href="?board=rotator">Edit deck ↗</a>
              </summary>
              <div class="rotation-slides-group-body">
                <?php foreach ($photoItems as $item):
                  $prow = $item['row'];
                  $purl = (string)$item['url'];
                  if ($legacyRotator):
                ?>
                <div class="rotation-card rotation-card-legacy" data-rotation-card>
                  <div class="rotation-card-head">
                    <div class="rotation-card-title">
                      <strong>Legacy single entry</strong>
                      <code>rotator.php</code>
                    </div>
                  </div>
                  <div class="rotation-card-meta">
                    <span class="pill warn">Switch deploy mode in Photo Rotator to split into per-photo entries</span>
                  </div>
                  <input type="hidden" name="<?= h($fieldKey) ?>[<?= (int)$pri ?>][url]" value="<?= h($purl) ?>">
                  <input type="hidden" name="<?= h($fieldKey) ?>[<?= (int)$pri ?>][dwell]" value="<?= h((string)($prow['dwell'] ?? '')) ?>">
                  <?php if (!empty($prow['from'])): ?><input type="hidden" name="<?= h($fieldKey) ?>[<?= (int)$pri ?>][from]" value="<?= h((string)$prow['from']) ?>"><?php endif; ?>
                  <?php if (!empty($prow['to'])): ?><input type="hidden" name="<?= h($fieldKey) ?>[<?= (int)$pri ?>][to]" value="<?= h((string)$prow['to']) ?>"><?php endif; ?>
                  <?php if (!empty($prow['off'])): ?><input type="hidden" name="<?= h($fieldKey) ?>[<?= (int)$pri ?>][off]" value="1"><?php endif; ?>
                </div>
                <?php $pri++; continue; endif;
                  $photoFile = rotator_rotation_parse_file($purl);
                  $photoMeta = $photoFile !== null ? rotator_deck_by_file($photoFile, $rawConf['rotator.PHOTOS'] ?? null) : null;
                  $photoLabel = rotation_page_label($purl);
                ?>
                <div class="rotation-card rotation-card-slide" data-rotation-card>
                  <div class="rotation-card-head">
                    <span class="drag-handle" title="Drag to reorder" draggable="true">⋮⋮</span>
                    <div class="rotation-card-title">
                      <strong data-rotation-label><?= h($photoLabel) ?></strong>
                      <code data-rotation-url-display><?= h($purl) ?></code>
                    </div>
                  </div>
                  <div class="rotation-card-grid rotation-card-grid-compact">
                    <div style="grid-column:1 / -1">
                      <label class="mini">URL</label>
                      <input type="text" name="<?= h($fieldKey) ?>[<?= (int)$pri ?>][url]" value="<?= h($purl) ?>" data-rotation-url readonly>
                    </div>
                    <div>
                      <label class="mini">Dwell (s)</label>
                      <input type="text" name="<?= h($fieldKey) ?>[<?= (int)$pri ?>][dwell]" value="<?= h((string)($prow['dwell'] ?? '')) ?>" placeholder="18" readonly>
                    </div>
                    <div>
                      <label class="mini">From hr</label>
                      <input type="text" name="<?= h($fieldKey) ?>[<?= (int)$pri ?>][from]" value="<?= h((string)($prow['from'] ?? '')) ?>" placeholder="0-23">
                    </div>
                    <div>
                      <label class="mini">To hr</label>
                      <input type="text" name="<?= h($fieldKey) ?>[<?= (int)$pri ?>][to]" value="<?= h((string)($prow['to'] ?? '')) ?>" placeholder="0-23">
                    </div>
                    <div>
                      <label class="mini" title="<?= h(rotation_weight_tooltip()) ?>">Weight</label>
                      <input type="text" name="<?= h($fieldKey) ?>[<?= (int)$pri ?>][weight]" value="<?= h((string)($prow['weight'] ?? '')) ?>" placeholder="1" title="<?= h(rotation_weight_tooltip()) ?>">
                    </div>
                  </div>
                  <div class="rotation-card-meta">
                    <label class="check" style="margin:0"><input type="checkbox" name="<?= h($fieldKey) ?>[<?= (int)$pri ?>][off]" <?= !empty($prow['off']) ? 'checked' : '' ?>> Skip this photo</label>
                    <?php
                    $photoProof = signage_presence_page_proof_label($screenPresence, $purl);
                    if ($photoProof !== ''): ?>
                      <span class="pill play-proof" title="Proof of play"><?= h($photoProof) ?></span>
                    <?php endif; ?>
                    <?php if (is_array($photoMeta) && trim((string)($photoMeta['group'] ?? '')) !== ''): ?>
                      <span class="pill">Group: <?= h((string)$photoMeta['group']) ?></span>
                    <?php endif; ?>
                    <div class="rotation-card-actions">
                      <a class="secondary" style="padding:6px 12px;text-decoration:none;font-size:13px" href="<?= h(signage_rotation_page_preview_url($purl, $screenKey)) ?>" target="_blank" rel="noopener" data-rotation-preview>Preview</a>
                    </div>
                  </div>
                </div>
                <?php $pri++; endforeach; ?>
              </div>
            </details>
            <?php continue; endif;
              $prow = $segment['row'] ?? [];
              $purl = (string)($segment['url'] ?? '');
            ?>
            <div class="rotation-card" data-rotation-card>
              <div class="rotation-card-head">
                <span class="drag-handle" title="Drag to reorder" draggable="true">⋮⋮</span>
                <div class="rotation-card-title">
                  <strong data-rotation-label><?= h(rotation_page_label($purl)) ?></strong>
                  <code data-rotation-url-display><?= h($purl !== '' ? $purl : 'board URL') ?></code>
                  <?php
                  $scheduleBadges = rotation_page_schedule_badges($prow);
                  $hasScheduleConfig = rotation_page_windows_label($prow) !== ''
                      || rotation_page_base_weight($prow) > 1
                      || rotation_page_weekdays($prow) !== null;
                  ?>
                  <div class="rotation-card-badges" data-rotation-badges<?= $hasScheduleConfig ? '' : ' hidden' ?>>
                    <?php foreach ($scheduleBadges as $badge): ?>
                    <span class="pill"><?= h($badge) ?></span>
                    <?php endforeach; ?>
                  </div>
                </div>
                <div class="rotation-card-head-meta">
                  <label class="check" style="margin:0"><input type="checkbox" name="<?= h($fieldKey) ?>[<?= (int)$pri ?>][off]" <?= !empty($prow['off']) ? 'checked' : '' ?>> Skip</label>
                  <label class="rotation-inline-dwell" title="Seconds on screen before advancing">
                    <span class="mini">Dwell</span>
                    <input type="text" name="<?= h($fieldKey) ?>[<?= (int)$pri ?>][dwell]" value="<?= h((string)($prow['dwell'] ?? '')) ?>" placeholder="60" aria-label="Dwell seconds">
                  </label>
                  <?php
                  $pageProof = signage_presence_page_proof_label($screenPresence, $purl);
                  if ($pageProof !== ''): ?>
                    <span class="pill play-proof" title="Proof of play"><?= h($pageProof) ?></span>
                  <?php endif; ?>
                  <?php if ($purl !== ''): ?>
                  <a class="secondary" style="padding:4px 10px;text-decoration:none;font-size:12px" href="<?= h(signage_rotation_page_preview_url($purl, $screenKey)) ?>" target="_blank" rel="noopener" data-rotation-preview>Preview</a>
                  <?php endif; ?>
                  <button type="button" class="rowdel" onclick="removeRotationCard(this, '<?= h($deckId) ?>')" title="Remove">×</button>
                </div>
              </div>
              <?php
              $dwellShow = trim((string)($prow['dwell'] ?? ''));
              $weightShow = trim((string)($prow['weight'] ?? ''));
              $scheduleLabel = rotation_page_schedule_label($prow);
              $schedulePreview = rotation_page_schedule_preview_text($prow);
              $editSummary = rotation_page_schedule_label($prow);
              if ($editSummary === '') {
                  if ($weightShow !== '' && (int)$weightShow > 1) {
                      $editSummary = 'Weight ' . (int)$weightShow;
                  } else {
                      $editSummary = 'Schedule & weight';
                  }
              } elseif ($weightShow !== '' && (int)$weightShow > 1) {
                  $editSummary = 'Weight ' . (int)$weightShow . ' · ' . $editSummary;
              }
              $windowRows = rotation_page_windows_form_rows($prow);
              $pageWeekdays = rotation_page_weekdays($prow);
              ?>
              <details class="rotation-card-edit">
                <summary><?= h($editSummary) ?></summary>
              <div class="rotation-card-grid">
                <div>
                  <label class="mini">URL</label>
                  <input type="text" name="<?= h($fieldKey) ?>[<?= (int)$pri ?>][url]" value="<?= h($purl) ?>"
                         placeholder="weather.php or rss.php?feed=ars" data-rotation-url required>
                </div>
                <div class="rotation-schedule-preview<?= $schedulePreview === 'Plays all day' ? ' is-empty' : '' ?>"
                     data-rotation-schedule-preview><strong>Schedule:</strong> <?= h($schedulePreview) ?></div>
                <div class="rotation-windows" data-rotation-windows>
                  <label class="mini">Time windows</label>
                  <div class="help" style="margin:0 0 8px">When this board may play. Leave <strong>From/To</strong> blank for all day. Per-window <strong>Weight</strong> overrides the default while that window is active (requires Weighted mode on the display).</div>
                  <?php foreach ($windowRows as $wi => $win): ?>
                  <div class="rotation-window-row" data-rotation-window-row>
                    <div class="rotation-window-field">
                      <label class="mini">From</label>
                      <input type="text" name="<?= h($fieldKey) ?>[<?= (int)$pri ?>][windows][<?= (int)$wi ?>][from]"
                             value="<?= h((string)($win['from'] ?? '')) ?>" placeholder="7 or 7:30" aria-label="From time">
                    </div>
                    <span class="rotation-window-sep" aria-hidden="true">–</span>
                    <div class="rotation-window-field">
                      <label class="mini">To</label>
                      <input type="text" name="<?= h($fieldKey) ?>[<?= (int)$pri ?>][windows][<?= (int)$wi ?>][to]"
                             value="<?= h((string)($win['to'] ?? '')) ?>" placeholder="9 or 9:00" aria-label="To time">
                    </div>
                    <div class="rotation-window-field">
                      <label class="mini" title="<?= h(rotation_weight_tooltip()) ?>">Weight</label>
                      <input type="text" name="<?= h($fieldKey) ?>[<?= (int)$pri ?>][windows][<?= (int)$wi ?>][weight]"
                             value="<?= h((string)($win['weight'] ?? '')) ?>" placeholder="1" title="<?= h(rotation_weight_tooltip()) ?>" aria-label="Window weight">
                    </div>
                    <button type="button" class="rowdel rotation-window-remove" title="Remove window"<?= count($windowRows) <= 1 ? ' hidden' : '' ?>>×</button>
                  </div>
                  <?php endforeach; ?>
                  <button type="button" class="secondary rotation-window-add">+ Add window</button>
                </div>
                <div class="rotation-page-weekdays">
                  <label class="mini">Active days</label>
                  <div class="help" style="margin:0 0 6px">Optional — limit this board to certain weekdays. All seven = every day.</div>
                  <?php rotation_admin_weekdays_html($fieldKey . '[' . (int)$pri . ']', $pageWeekdays); ?>
                </div>
                <div class="rotation-weight-field">
                  <label class="mini" title="<?= h(rotation_weight_tooltip()) ?>">Weight (default)</label>
                  <input type="text" name="<?= h($fieldKey) ?>[<?= (int)$pri ?>][weight]" value="<?= h((string)($prow['weight'] ?? '')) ?>" placeholder="1" title="<?= h(rotation_weight_tooltip()) ?>">
                  <div class="help" style="margin-top:4px">Used when Weighted mode is on and no window weight applies.</div>
                </div>
              </div>
              </details>
            </div>
            <?php $pri++; endforeach; ?>
          </div>


