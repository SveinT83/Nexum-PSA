Risk settings are managed from Admin -> Risk Settings.

The settings page controls defaults for new records:

- Default assessment scope.
- Default assessment status.
- Default risk item likelihood.
- Default risk item impact.
- Default risk item status.
- Default risk item review interval.

These defaults apply to new assessments and newly created risk items. Existing records keep their
stored values and history.

Likelihood and impact remain limited to 1 through 5. The RiskItem model calculates score from those
two values when the item is saved.
