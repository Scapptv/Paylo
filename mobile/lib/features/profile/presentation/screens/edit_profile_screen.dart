import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';

import 'package:paylo/core/errors/api_exception.dart';
import 'package:paylo/core/theme/app_theme.dart';
import 'package:paylo/features/auth/domain/user.dart';
import 'package:paylo/features/profile/data/profile_repository.dart';
import 'package:paylo/shared/widgets/app_button.dart';

class EditProfileScreen extends ConsumerStatefulWidget {
  const EditProfileScreen({super.key, required this.user});
  final User user;

  @override
  ConsumerState<EditProfileScreen> createState() => _EditProfileScreenState();
}

class _EditProfileScreenState extends ConsumerState<EditProfileScreen> {
  final _formKey = GlobalKey<FormState>();
  late final _name = TextEditingController(text: widget.user.name);
  late final _phone = TextEditingController(text: widget.user.phone ?? '');
  bool _saving = false;

  @override
  void dispose() {
    _name.dispose();
    _phone.dispose();
    super.dispose();
  }

  Future<void> _save() async {
    if (!_formKey.currentState!.validate()) return;
    setState(() => _saving = true);

    try {
      await ref.read(profileRepositoryProvider).update(
            name: _name.text.trim(),
            phone: _phone.text.trim(),
          );

      ref.invalidate(profileProvider);

      if (!mounted) return;
      ScaffoldMessenger.of(context).showSnackBar(const SnackBar(
        content: Text('Profil yeniləndi'),
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
      appBar: AppBar(title: const Text('Profil məlumatları')),
      body: SafeArea(
        child: Form(
          key: _formKey,
          child: ListView(
            padding: const EdgeInsets.all(20),
            children: [
              _label('Ad'),
              TextFormField(
                controller: _name,
                validator: (v) => (v == null || v.trim().length < 2) ? 'Minimum 2 simvol' : null,
              ),
              const SizedBox(height: 16),

              _label('Telefon'),
              TextFormField(
                controller: _phone,
                keyboardType: TextInputType.phone,
                validator: (v) => (v == null || v.trim().length < 9) ? 'Yanlış nömrə' : null,
              ),
              const SizedBox(height: 16),

              _label('E-poçt (dəyişdirilə bilməz)'),
              TextFormField(
                initialValue: widget.user.email,
                enabled: false,
                style: AppTextStyles.body(14, color: AppColors.muted),
              ),

              const SizedBox(height: 32),
              AppButton(label: 'Yadda saxla', loading: _saving, onPressed: _save),
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
