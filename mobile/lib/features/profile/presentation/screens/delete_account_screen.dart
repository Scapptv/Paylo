import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';

import 'package:paylo/core/errors/api_exception.dart';
import 'package:paylo/core/theme/app_theme.dart';
import 'package:paylo/features/auth/presentation/controllers/auth_controller.dart';
import 'package:paylo/features/profile/data/profile_repository.dart';
import 'package:paylo/shared/widgets/app_button.dart';

class DeleteAccountScreen extends ConsumerStatefulWidget {
  const DeleteAccountScreen({super.key});

  @override
  ConsumerState<DeleteAccountScreen> createState() => _DeleteAccountScreenState();
}

class _DeleteAccountScreenState extends ConsumerState<DeleteAccountScreen> {
  final _password = TextEditingController();
  bool _agreed = false;
  bool _processing = false;

  @override
  void dispose() {
    _password.dispose();
    super.dispose();
  }

  Future<void> _submit() async {
    if (_password.text.isEmpty || !_agreed) return;
    setState(() => _processing = true);

    try {
      await ref.read(profileRepositoryProvider).deleteAccount(password: _password.text);
      await ref.read(authControllerProvider.notifier).logout();
    } on ApiException catch (e) {
      _showError(e);
    } finally {
      if (mounted) setState(() => _processing = false);
    }
  }

  void _showError(ApiException e) {
    final msg = e is ValidationException ? (e.firstError() ?? e.message) : e.message;
    ScaffoldMessenger.of(context).showSnackBar(SnackBar(
      content: Text(msg),
      backgroundColor: AppColors.danger,
      behavior: SnackBarBehavior.floating,
    ),);
  }

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      appBar: AppBar(title: const Text('Hesabı sil')),
      body: SafeArea(
        child: ListView(
          padding: const EdgeInsets.all(20),
          children: [
            const Icon(Icons.warning_amber_rounded, color: AppColors.danger, size: 56),
            const SizedBox(height: 16),
            Text(
              'Bu addım geri qayıtmazdır',
              style: AppTextStyles.display(22, weight: FontWeight.w600, color: AppColors.danger),
              textAlign: TextAlign.center,
            ),
            const SizedBox(height: 12),
            Text(
              'Profil məlumatlarınız (ad, telefon, e-poçt) silinəcək. Ledger qeydləri '
              'maliyyə uçotu üçün anonimləşdirilmiş şəkildə qalır. Bütün cihazlardan '
              'çıxış edəcəksiniz.',
              style: AppTextStyles.body(13, color: AppColors.text2),
              textAlign: TextAlign.center,
            ),
            const SizedBox(height: 32),

            Text('Davam etmək üçün şifrəni daxil et'.toUpperCase(),
                style: AppTextStyles.mono(10, letterSpacing: 0.2),),
            const SizedBox(height: 8),
            TextField(controller: _password, obscureText: true),

            const SizedBox(height: 20),
            CheckboxListTile(
              value: _agreed,
              onChanged: (v) => setState(() => _agreed = v ?? false),
              controlAffinity: ListTileControlAffinity.leading,
              contentPadding: EdgeInsets.zero,
              activeColor: AppColors.danger,
              checkColor: AppColors.text,
              side: const BorderSide(color: AppColors.border),
              title: Text(
                'Anlayıram ki, hesabımı silmək geri qayıtmazdır.',
                style: AppTextStyles.body(13, color: AppColors.text2),
              ),
            ),

            const SizedBox(height: 24),
            SizedBox(
              width: double.infinity,
              child: ElevatedButton(
                onPressed: (_password.text.isEmpty || !_agreed || _processing) ? null : _submit,
                style: ElevatedButton.styleFrom(
                  backgroundColor: AppColors.danger,
                  foregroundColor: AppColors.text,
                  padding: const EdgeInsets.symmetric(vertical: 16),
                ),
                child: _processing
                    ? const SizedBox(width: 20, height: 20, child: CircularProgressIndicator(strokeWidth: 2, color: AppColors.text))
                    : const Text('Hesabı həmişəlik sil'),
              ),
            ),
            const SizedBox(height: 12),
            AppButton(
              label: 'Ləğv et',
              variant: AppButtonVariant.outlined,
              onPressed: () => Navigator.pop(context),
            ),
          ],
        ),
      ),
    );
  }
}
