import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';

import 'package:paylo/core/errors/api_exception.dart';
import 'package:paylo/core/theme/app_theme.dart';
import 'package:paylo/features/profile/data/profile_repository.dart';
import 'package:paylo/shared/widgets/app_button.dart';

class ChangePasswordScreen extends ConsumerStatefulWidget {
  const ChangePasswordScreen({super.key});

  @override
  ConsumerState<ChangePasswordScreen> createState() => _ChangePasswordScreenState();
}

class _ChangePasswordScreenState extends ConsumerState<ChangePasswordScreen> {
  final _formKey = GlobalKey<FormState>();
  final _current = TextEditingController();
  final _newPassword = TextEditingController();
  final _confirm = TextEditingController();
  bool _saving = false;
  bool _obscureCurrent = true;
  bool _obscureNew = true;

  @override
  void dispose() {
    _current.dispose();
    _newPassword.dispose();
    _confirm.dispose();
    super.dispose();
  }

  Future<void> _submit() async {
    if (!_formKey.currentState!.validate()) return;
    setState(() => _saving = true);

    try {
      await ref.read(profileRepositoryProvider).changePassword(
            currentPassword: _current.text,
            newPassword: _newPassword.text,
          );

      if (!mounted) return;
      ScaffoldMessenger.of(context).showSnackBar(const SnackBar(
        content: Text('Şifrə yeniləndi. Digər cihazlardan çıxış edildi.'),
        backgroundColor: AppColors.success,
        behavior: SnackBarBehavior.floating,
      ),);
      Navigator.pop(context);
    } on ApiException catch (e) {
      _showError(e);
    } finally {
      if (mounted) setState(() => _saving = false);
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
      appBar: AppBar(title: const Text('Şifrəni dəyiş')),
      body: SafeArea(
        child: Form(
          key: _formKey,
          child: ListView(
            padding: const EdgeInsets.all(20),
            children: [
              _label('Cari şifrə'),
              TextFormField(
                controller: _current,
                obscureText: _obscureCurrent,
                decoration: InputDecoration(
                  suffixIcon: IconButton(
                    icon: Icon(_obscureCurrent ? Icons.visibility_off : Icons.visibility, size: 18),
                    onPressed: () => setState(() => _obscureCurrent = !_obscureCurrent),
                  ),
                ),
                validator: (v) => (v == null || v.isEmpty) ? 'Tələb olunur' : null,
              ),
              const SizedBox(height: 16),

              _label('Yeni şifrə'),
              TextFormField(
                controller: _newPassword,
                obscureText: _obscureNew,
                decoration: InputDecoration(
                  hintText: 'Minimum 8 simvol, hərf və rəqəm',
                  suffixIcon: IconButton(
                    icon: Icon(_obscureNew ? Icons.visibility_off : Icons.visibility, size: 18),
                    onPressed: () => setState(() => _obscureNew = !_obscureNew),
                  ),
                ),
                validator: (v) {
                  if (v == null || v.length < 8) return 'Minimum 8 simvol';
                  if (!RegExp(r'[A-Za-z]').hasMatch(v)) return 'Ən azı 1 hərf';
                  if (!RegExp(r'[0-9]').hasMatch(v)) return 'Ən azı 1 rəqəm';
                  return null;
                },
              ),
              const SizedBox(height: 16),

              _label('Yeni şifrəni təkrar et'),
              TextFormField(
                controller: _confirm,
                obscureText: _obscureNew,
                validator: (v) => v != _newPassword.text ? 'Şifrələr uyğun gəlmir' : null,
              ),

              const SizedBox(height: 16),
              Container(
                padding: const EdgeInsets.all(12),
                decoration: BoxDecoration(
                  color: AppColors.warning.withValues(alpha: 0.08),
                  border: Border.all(color: AppColors.warning.withValues(alpha: 0.3)),
                ),
                child: Text(
                  'Şifrə dəyişdikdə digər bütün cihazlardan avtomatik çıxış olunacaq.',
                  style: AppTextStyles.body(11, color: AppColors.warning),
                ),
              ),

              const SizedBox(height: 24),
              AppButton(label: 'Şifrəni dəyiş', loading: _saving, onPressed: _submit),
            ],
          ),
        ),
      ),
    );
  }

  Widget _label(String text) => Padding(
        padding: const EdgeInsets.only(bottom: 8),
        child: Text(text.toUpperCase(), style: AppTextStyles.mono(10, letterSpacing: 0.2)),
      );
}
