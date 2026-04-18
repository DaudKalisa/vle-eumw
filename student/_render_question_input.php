<?php
// Renders the input control for a single question
// Expects: $q (question row), $q_id, $existing_answers
$_saved = $existing_answers[$q_id] ?? '';
?>
<?php if ($q['question_type'] === 'multiple_choice' && !empty($q['options'])): ?>
    <?php $opts = json_decode($q['options'], true); if (is_array($opts)): foreach ($opts as $oi => $opt): ?>
        <div class="form-check mb-2">
            <input class="form-check-input" type="radio" 
                   name="answers[<?php echo $q_id; ?>]" 
                   id="q_<?php echo $q_id; ?>_opt_<?php echo $oi; ?>" 
                   value="<?php echo htmlspecialchars($opt); ?>"
                   <?php echo ($_saved == $opt) ? 'checked' : ''; ?> required>
            <label class="form-check-label" for="q_<?php echo $q_id; ?>_opt_<?php echo $oi; ?>"><?php echo htmlspecialchars($opt); ?></label>
        </div>
    <?php endforeach; endif; ?>

<?php elseif ($q['question_type'] === 'checkboxes' && !empty($q['options'])): ?>
    <?php 
    $opts = json_decode($q['options'], true);
    $_saved_arr = json_decode($_saved, true) ?: [];
    if (is_array($opts)): foreach ($opts as $oi => $opt): ?>
        <div class="form-check mb-2">
            <input class="form-check-input" type="checkbox" 
                   name="answers[<?php echo $q_id; ?>][]" 
                   id="q_<?php echo $q_id; ?>_opt_<?php echo $oi; ?>" 
                   value="<?php echo htmlspecialchars($opt); ?>"
                   <?php echo in_array($opt, $_saved_arr) ? 'checked' : ''; ?>>
            <label class="form-check-label" for="q_<?php echo $q_id; ?>_opt_<?php echo $oi; ?>"><?php echo htmlspecialchars($opt); ?></label>
        </div>
    <?php endforeach; endif; ?>

<?php elseif ($q['question_type'] === 'dropdown' && !empty($q['options'])): ?>
    <?php $opts = json_decode($q['options'], true); ?>
    <select class="form-select" name="answers[<?php echo $q_id; ?>]" required>
        <option value="">-- Select an option --</option>
        <?php if (is_array($opts)): foreach ($opts as $opt): ?>
            <option value="<?php echo htmlspecialchars($opt); ?>" <?php echo ($_saved == $opt) ? 'selected' : ''; ?>><?php echo htmlspecialchars($opt); ?></option>
        <?php endforeach; endif; ?>
    </select>

<?php elseif ($q['question_type'] === 'true_false'): ?>
    <div class="form-check mb-2">
        <input class="form-check-input" type="radio" name="answers[<?php echo $q_id; ?>]" id="q_<?php echo $q_id; ?>_true" value="True" <?php echo ($_saved === 'True') ? 'checked' : ''; ?> required>
        <label class="form-check-label" for="q_<?php echo $q_id; ?>_true">True</label>
    </div>
    <div class="form-check mb-2">
        <input class="form-check-input" type="radio" name="answers[<?php echo $q_id; ?>]" id="q_<?php echo $q_id; ?>_false" value="False" <?php echo ($_saved === 'False') ? 'checked' : ''; ?> required>
        <label class="form-check-label" for="q_<?php echo $q_id; ?>_false">False</label>
    </div>

<?php elseif ($q['question_type'] === 'short_answer'): ?>
    <input type="text" class="form-control" name="answers[<?php echo $q_id; ?>]" value="<?php echo htmlspecialchars($_saved); ?>" placeholder="Type your answer here..." required>

<?php else: ?>
    <textarea class="form-control" name="answers[<?php echo $q_id; ?>]" rows="4" placeholder="Type your answer here..." required><?php echo htmlspecialchars($_saved); ?></textarea>
<?php endif; ?>
