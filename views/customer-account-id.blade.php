<div class="card mb-4">
	<div>
		<label for="sage_account_id" class="block">Sage Account Number</label>
		<input type="text" id="sage_account_id" name="sage_account_id" value="{{ old('sage_account_id', $customer->sage_account_id) }}" class="{{ $errors->has('sage_account_id') ? 'has-error' : '' }}" autocomplete="off">
	</div>
</div>
