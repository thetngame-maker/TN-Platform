export class KernelError extends Error {
  constructor(message, code = 'KERNEL_ERROR', details = {}) {
    super(message);
    this.name = 'KernelError';
    this.code = code;
    this.details = details;
  }
}

export class DependencyError extends KernelError {
  constructor(message, details = {}) { super(message, 'DEPENDENCY_ERROR', details); }
}

export class LifecycleError extends KernelError {
  constructor(message, details = {}) { super(message, 'LIFECYCLE_ERROR', details); }
}
